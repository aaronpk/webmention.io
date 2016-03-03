class WebmentionProcessor
  begin
    include SuckerPunch::Job
  rescue NameError
  end

  # Run as a full thread instead of a fiber
  # See https://github.com/celluloid/celluloid/wiki/Fiber-stack-errors
  if method_defined? :task_class
    task_class TaskThread
  end

  def perform(event)
    process_mention event[:username], event[:source], event[:target], event[:protocol], event[:token]
  end

  def error_status(token, source, target, error, error_description=nil) 
    status = {
      :status => error,
      :source => source,
      :target => target
    }
    if error_description
      status[:summary] = error_description
    end
    WebmentionProcessor.update_status @redis, token, status
  end

  def self.update_status(redis, token, data)
    redis.setex "webmention:status:#{token}", 86400*3, data.to_json
  end

  # Handles actually verifying source links to target, returning the list of errors based on the webmention errors
  def process_mention(username, source, target, protocol, token)
    @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port

    target_account = Account.first :username => username
    if target_account.nil?
      error = 'target_not_found'
      error_status token, source, target, error
      return nil, error
    end
    if source == target
      error = 'invalid_target'
      error_status token, source, target, error
      return nil, error
    end

    #puts "Verifying link exists from #{source} to #{target}"

    begin
      target_uri = URI.parse(target)
      target_domain = target_uri.host
    rescue
      error = 'invalid_target'
      error_status token, source, target, error, 'target could not be parsed as a URL'
      return nil, error
    end

    if target_domain.nil?
      error = 'invalid_target'
      error_status token, source, target, error, 'target domain was empty'
      return nil, error
    end

    begin
      source_uri = URI.parse(source)
    rescue
      error = 'invalid_source'
      error_status token, source, target, error, 'source could not be parsed as a URL'
      return nil, error
    end

    source_data = XRay.parse source, target

    if source_data.nil?
      error = 'invalid_source'
      error_status token, source, target, error, 'Error retrieving source. No result returned from XRay.'
      return nil, error
    end

    if source_data.class == String
      puts "\tError retrieving source: #{source_data}"
      error_status token, source, target, source_data
      return nil, source_data
    end

    site = Site.first_or_create :account => target_account, :domain => target_domain

    puts "Processing... s=#{source} t=#{target}"

    # If the page already exists, use that record. Otherwise create it and find out what kind of object is on the page.
    # This currently uses the Ruby mf2 parser to parse the target URL
    page = create_page_in_site site, target

    link = Link.first_or_create({:page => page, :href => source}, {:site => site})

    already_registered = link[:verified]

    # Parse for microformats and look for "like", "invite", "rsvp", or other post types
    parsed = false
    source_is_bridgy = source.start_with? 'https://www.brid.gy/', 'https://brid.gy', 'https://brid-gy.appspot.com/'

    # Default message. Overridden for some post types below.
    message = "[mention] #{source} linked to #{target} (#{protocol})"

    begin
      add_author_to_link source_data, link
      add_mf2_data_to_link source_data, link

      # Detect post type (reply, like, reshare, RSVP, mention) and silo and
      # generate custom notification message.
      url = !link.url.blank? ? link.url : source

      subject = link.author_text url
      subject_html = link.author_html "someone", url

      phrase = get_phrase_and_set_type source_data, link, source, target

      message = "[#{source_is_bridgy ? 'bridgy' : 'mention'}] #{subject} #{phrase} #{target}"
      if subject != url
        message += " (#{url})"
      end

      link.sentence = "#{subject} #{phrase} #{target}"
      link.sentence_html = "#{subject_html} #{phrase} <a href=\"#{target}\">#{target}</a>"
    rescue => e
      # Ignore errors trying to parse for upgraded microformats
      puts "Error while parsing microformats #{e.message}"
      puts e.backtrace
    end

    # Only send notifications about new webmentions
    if site.supports_notifications?
      if !already_registered
        puts "Queuing notification: #{message}"

        if link.type == "reply"
          NotificationQueue.send_notification link.page.site, message
        else
          NotificationQueue.queue_notification link
        end
      else
        puts "Already sent notification: #{message}"
      end # notification
    end

    # If a callback URL is defined for this site, send to the callback now
    if site.callback_url
      begin
        puts "Sending to callback URL: #{site.callback_url}"

        jf2 = Formats.build_jf2_from_link(link)

        data = {
          secret: site.callback_secret,
          source: source,
          target: target,
          post: jf2
        }

        RestClient::Request.execute(:method => :post,
          :url => site.callback_url,
          :payload => data.to_json,
          :headers => {:content_type => 'application/json'},
          :ssl_ca_file => './helpers/ca-bundle.crt')
        puts "... success!"
      rescue => e
        puts "Failed to send to callback URL"
        puts e.inspect
      end
    end

    # Publish on Redis for realtime comments
    if @redis && parsed
      @redis.publish "webmention.io::#{target}", {
        type: 'webmention',
        element_id: "external_#{source.gsub(/[\/:\.]+/, '_')}",
        author: {
          name: link.author_name,
          url: link.author_url,
          photo: link.author_photo
        },
        url: url,
        name: link.name,
        content: link.content,
        published: link.published,
        published_ts: link.published_ts
      }.to_json
    end

    puts "\tsuccess"

    link.token = token
    link.verified = true
    link.save

    WebmentionProcessor.update_status @redis, token, {
      :status => 'success',
      :source => source,
      :target => target,
      :data => Formats.build_jf2_from_link(link)
    }

    return link, 'success'
  end

  def create_page_in_site(site, target)
    page = Page.first :site => site, :href => target
    if page.nil?
      page = Page.new
      page.site = site
      page.account = site.account
      page.href = target

      begin
        parsed = Microformats2.parse target

        # Determine the type of page the target is. It might be an event or a photo for example
        if event = maybe_get(parsed, 'event')
          page.type = 'event'
          name = maybe_get event, 'name'
          # TODO: add the date and maybe location so that the name of the event is:
          # Homebrew Website Club on 2015-07-29
          page.name = name.to_s if name

        elsif entry = maybe_get(parsed, 'entry')
          name = maybe_get entry, 'name'
          page.name = name.to_s if name
          page.type = 'entry'

          if maybe_get entry, 'photo'
            page.type = 'photo'
          elsif maybe_get entry, 'video'
            page.type = 'video'
          elsif maybe_get entry, 'audio'
            page.type = 'audio'
          end

        end
      rescue => e
        puts "Error parsing: #{e.inspect}"
        puts e.backtrace
      end

      page.save
    end
    page
  end

  def get_phrase_and_set_type(entry, link, source, target)
    url = !link.url.blank? ? link.url : source
    source_is_twitter = url.start_with? 'https://twitter.com/'
    source_is_gplus = url.start_with? 'https://plus.google.com/'

    if rsvp = entry['rsvp']
      phrase = "RSVPed #{rsvp} to"
      link.type = "rsvp-#{rsvp}"

    elsif entry['invitees']
      phrase = 'was invited to'
      link.type = "invite"

    elsif repost_of = entry['repost-of']
      phrase = (source_is_twitter ? 'retweeted a tweet' : 'reshared a post')
      if !repost_of.include? target
        # for bridgy
        # TODO: when the repost-of link is not the one receiving the webmention, "that linked to" is not necessarily correct
        # It's only correct when the target URL is in the contents of the repost, e.g. if the repost included all the contents of the original
        phrase += " that linked to"
        link.is_direct = false
      end
      link.type = "repost"

    elsif like_of = entry['like-of']
      phrase = (source_is_twitter ? 'favorited a tweet' : source_is_gplus ? '+1\'d a post' : 'liked a post')
      if !like_of.include? target
        # for bridgy
        # TODO: when the like-of link is not the one receiving the webmention, "that linked to" is not necessarily correct
        # It's only correct when the target URL is in the contents of the like, e.g. if the like included all the contents of the original
        phrase += " that linked to"
        link.is_direct = false
      end
      link.type = "like"

    elsif bookmark_of = entry['bookmark-of']
      phrase = (source_is_twitter ? 'bookmarked a tweet' : 'bookmarked a post')
      if !bookmark_of.include? target
        # for bridgy
        # TODO: when the bookmark-of link is not the one receiving the webmention, "that linked to" is not necessarily correct
        phrase += " that linked to"
        link.is_direct = false
      end
      link.type = "bookmark"

    elsif in_reply_to = entry['in-reply-to']
      if source_is_twitter
        phrase = "replied '#{link.snippet}' to a tweet"
      else
        phrase = "commented '#{link.snippet}' on a post"
      end
      if !in_reply_to.include? target
        # for bridgy
        phrase += " that linked to"
        # TODO: when the in-reply-to link is not the one receiving the webmention, "that linked to" is not necessarily correct
        link.is_direct = false
      end
      link.type = "reply"

    else
      phrase = "posted '#{link.snippet}' linking to"
      link.type = "link"
    end

    link.save

    phrase
  end

  def add_author_to_link(entry, link)
    link.author_url = ""
    link.author_name = ""
    link.author_photo = ""

    # kinda a hack for bridgy invites
    if entry && entry['invitee']
      link.author_url = entry['invitee'][0]
    end

    if entry && entry['author'] && entry['author']['type'] == 'card'
      link.author_url = entry['author']['url'] if entry['author']['url']
      link.author_name = entry['author']['name'] if entry['author']['name']
      link.author_photo = entry['author']['photo'] if entry['author']['photo']
      if link.site.archive_avatars
        # Replace the author photo with an archive URL
        archive_photo_url = Avatar.get_avatar_archive_url link.author_photo
        link.author_photo = archive_photo_url
      end
      link.save
    end
  end

  def add_mf2_data_to_link(entry, link)
    link.url = entry['url']
    link.name = entry['name']

    if entry['summary']
      link.summary = entry['summary']
    end

    if entry['content']
      if entry['content']['html']
        link.content = entry['content']['html']
      end
      link.content_text = entry['content']['text']
    end

    if !link.url.blank?
      # Set link.url relative to source URL from the webmention
      link.url = Microformats2::AbsoluteUri.new(link.href, link.url).absolutize
    end

    published = entry['published']
    if !published.blank?
      date = DateTime.parse(published.to_s)
      link.published = date.to_time # Convert to UTC (uses ENV timezone)
      # only set the timezone offset if it was provided in the original publish date string
      if published.to_s.match(/[+-]\d{2}:?\d{2}/)
        link.published_offset = date.utc_offset
      end
      link.published_ts = date.to_time.to_i # store UTC unix timestamp
    end

    syndications = entry['syndication']
    if !syndications.blank?
      link.syndication = syndications.to_json
    end

    link.save
  end

  def maybe_get(obj, method)
    begin
      obj.send method
    rescue
      nil
    end
  end

end
