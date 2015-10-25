class WebmentionProcessor
  include SuckerPunch::Job
  # Run as a full thread instead of a fiber
  # See https://github.com/celluloid/celluloid/wiki/Fiber-stack-errors
  task_class TaskThread

  def perform(event)
    link, result = process_mention event[:username], event[:source], event[:target], event[:protocol]

    # Delete invalid mentions from the database
    # TODO: Delay this, or cache this when we implement a webmention status endpoint
    if link and result != 'success'
      puts "mention was invalid, deleting"
      link.destroy
    end
  end

  # Handles actually verifying source links to target, returning the list of errors based on the webmention errors
  def process_mention(username, source, target, protocol)

    puts "Verifying link exists from #{source} to #{target}"

    target_account = Account.first :username => username
    return nil, 'target_not_found' if target_account.nil?

    return nil, 'invalid_target' if source == target

    begin
      target_domain = URI.parse(target).host
    rescue
      return nil, 'invalid_target' if target_domain.nil?
    end
    return nil, 'target_not_found' if target_domain.nil?

    agent = Mechanize.new {|agent|
      agent.user_agent_alias = "Mac Safari"
    }

    site = Site.first_or_create :account => target_account, :domain => target_domain

    # If the page already exists, use that record. Otherwise create it and find out what kind of object is on the page
    page = Page.first :site => site, :href => target
    if page.nil?
      page = Page.new 
      page.site = site
      page.account = target_account
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

          if maybe_get entry, 'photo'
            page.type = 'photo'
          elsif maybe_get entry, 'video'
            page.type = 'video'
          end

        end
      rescue
      end

      page.save
    end

    link = Link.first_or_create({:page => page, :href => source},{:site => site})

    already_registered = link[:verified]

    begin
      scraper = agent.get source
    rescue
      return link, 'source_not_found' if scraper.nil?
    end

    valid = scraper.link_with(:href => target) != nil

    return link, 'no_link_found' if !valid

    # Parse for microformats and look for "like", "invite", "rsvp", or other post types
    parsed = false
    bridgy = source.start_with? 'https://www.brid.gy/', 'https://brid-gy.appspot.com/'

    # Default message. Overridden for some post types below.
    message = "[mention] #{source} linked to #{target} (#{protocol})"

    begin
      parsed = Microformats2.parse source

      entry = maybe_get parsed, 'entry'
      entry = maybe_get parsed, 'event' if entry.nil?
      if entry
        author = maybe_get entry, 'author'
        author = maybe_get entry, 'invitee' if author.nil?
        if author
          link.author_name = author.format.name.to_s
          link.author_url = maybe_get(author.format, 'url').to_s
          if link.author_url
            link.author_url = Microformats2::AbsoluteUri.new(link.href, link.author_url).absolutize
          end
          link.author_photo = author.format.photo.to_s
          if link.author_photo
            link.author_photo = Microformats2::AbsoluteUri.new(link.href, link.author_photo).absolutize
            if site.archive_avatars
              # Replace the author photo with an archive URL
              archive_photo_url = Avatar.get_avatar_archive_url link.author_photo
              puts "Storing photo url: #{archive_photo_url}"
              link.author_photo = archive_photo_url
            end
          end
        end

        link.url = maybe_get entry, 'url'
        link.name = maybe_get entry, 'name'
        link.summary = Sanitize.fragment((maybe_get entry, 'summary').to_s, Sanitize::Config::BASIC)
        link.content = Sanitize.fragment((maybe_get entry, 'content').to_s, Sanitize::Config::BASIC)

        if link.url
          # Set link.url relative to source URL from the webmention
          link.url = Microformats2::AbsoluteUri.new(link.href, link.url).absolutize
        end

        published = maybe_get entry, 'published'
        if published
          link.published = DateTime.parse(published.to_s) # has timezone information now, will be discarded when saved to the db
          link.published_ts = DateTime.parse(published.to_s).to_time.to_i
        end

        # Detect post type (reply, like, reshare, RSVP, mention) and silo and
        # generate custom notification message.
        url = link.url ? link.url : source
        twitter = url.start_with? 'https://twitter.com/'
        gplus = url.start_with? 'https://plus.google.com/'

        if link.author_name
          subject = link.author_name
          subject_html = "<a href=\"#{link.author_url}\">#{link.author_name}</a>"
        elsif link.author_url
          subject = link.author_url
          subject_html = "<a href=\"#{link.author_url}\">#{link.author_url}</a>"
        else
          subject = url
          subject_html = "<a href=\"#{url}\">#{url}</a>"
        end

        snippet = Sanitize.fragment(link.content).strip.gsub "\n", ' '
        if snippet.length > 140
          snippet = snippet[0, 140] + '...'
        end

        # TODO(snarfed): store in db
        rsvps = maybe_get entry, 'rsvps'
        if rsvps
          phrase = "RSVPed #{rsvps.join(', ')} to"
          link.type = "rsvp-#{rsvps[0]}"

        elsif maybe_get entry, 'invitees'
          phrase = 'was invited to'
          link.type = "invite"

        elsif repost_of = get_referenced_url(entry, 'repost_ofs')
          phrase = (twitter ? 'retweeted a tweet' : 'reshared a post') 
          if !repost_of.include? target
            phrase += " that linked to"
            link.is_direct = false
          end
          link.type = "repost"

        elsif like_of = get_referenced_url(entry, 'like_ofs')
          puts like_of.inspect
          phrase = (twitter ? 'favorited a tweet' : gplus ? '+1ed a post' : 'liked a post')
          if !like_of.include? target
            phrase += " that linked to"
            link.is_direct = false
          end
          link.type = "like"

        elsif in_reply_to = get_referenced_url(entry, 'in_reply_tos')
          if twitter
            phrase = "replied '#{snippet}' to a tweet"
          else
            phrase = "commented '#{snippet}' on a post"
          end
          if !in_reply_to.include? target
            puts "in reply to URL is different from the target: #{in_reply_to}"
            phrase += " that linked to"
            link.is_direct = false
          end
          link.type = "reply"

        else
          phrase = "posted '#{snippet}' linking to"
          link.type = "link"
        end

        message = "[#{bridgy ? 'bridgy' : 'mention'}] #{subject} #{phrase} #{target}"
        if subject != url
          message += " (#{url})"
        end

        link.sentence = "#{subject} #{phrase} #{target}"
        link.sentence_html = "#{subject_html} #{phrase} <a href=\"#{target}\">#{target}</a>"
      end

      #link.html = scraper.body
    rescue => e
      # Ignore errors trying to parse for upgraded microformats
      puts "Error while parsing microformats #{e.message}"
      puts e.backtrace
    end

    # Only send notifications about new webmentions
    if !already_registered
      puts "Queuing notification: #{message}"

      if link.type == "reply"
        NotificationQueue.send_notification link.page.site, message
      else
        NotificationQueue.queue_notification link, message
      end
    else
      puts "Already sent notification: #{message}"
    end # notification

    # If a callback URL is defined for this site, send to the callback now
    if(site.callback_url)
      begin
        puts "Sending to callback URL: #{site.callback_url}"
        RestClient.post site.callback_url, {
          secret: site.callback_secret,
          source: source,
          target: target,
          type: link.type,
          author_name: link.author_name,
          author_photo: link.author_photo,
          author_url: link.author_url,
          url: url,
          name: link.name,
          summary: link.summary,
          content: link.content,
          published: link.published
        }
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

    link.verified = true
    link.save
    return link, 'success'
  end

  def get_referenced_url(obj, method)
    # if obj[method] is an h-cite object, fetch the "url" property from the properties object
    # otherwise, if obj[method] is just a string, return it

    values = maybe_get obj, method
    # obj will be an h-entry
    # method will be "in-reply-tos"
    # value will be the in-reply-tos array which may be h-cites or just strings

    return nil if values.nil?

    urls = []

    values.each do |value|
      # Currently the Ruby parser incorrectly parses the "in-reply-to" as text if it's actually a nested h-cite
      # Drop down to the to_hash version instead

      if value.class == Microformats2::Property::Url
        urls << value.to_s
      else
        hash = value.to_hash

        if type = hash[:type]
          if type.include? 'h-cite'
            if properties = hash[:properties]
              if url = properties[:url]
                urls << url
              end
            end
          end
        end
      end
    end

    return urls.flatten
  end

  def maybe_get(obj, method)
    begin
      obj.send method
    rescue
      nil
    end
  end

end
