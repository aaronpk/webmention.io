class NotificationQueue

  def self.queue_notification(link, message)
    #self.send_notification link, message

    buffer_period = 120

    if !@redis
      puts "Connecting to Redis"
      @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port
    end

    site_id = link.page.site.id

    # Add an entry indicating this site has queued mentions
    @redis.sadd "webmention::queued", site_id

    # Create clusters for source and target, adding the notification to both
    @redis.sadd "webmention::#{site_id}::source::#{link.type}::#{link.is_direct}::#{link.source}", link.id
    @redis.sadd "webmention::#{site_id}::target::#{link.target}", link.id

    # Set a timer for this webmention for 60 seconds from now
    @redis.zadd "webmention::#{site_id}::timers", (Time.now.to_i+buffer_period), link.id
  end

  def self.process_notifications
    @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port

    # Get a list of all the accounts that have pending mentions
    sites = @redis.smembers "webmention::queued"
    sites.each do |site_id|
      site = Site.first :id => site_id
      puts "Processing account: #{site_id} #{site.account.domain}"

      # Find any timers that have expired
      timers = @redis.zrangebyscore "webmention::#{site_id}::timers", 0, Time.now.to_i

      if timers.count == 0
        puts "\tno timers"
      end

      timers.each do |link_id|
        link = Link.get link_id
        next if link.nil?

        is_direct = link.is_direct

        puts "Processing timer #{link_id} (source: #{link.source} target: #{link.target}"

        # Get the members of both the source and target lists.
        # Yes, it looks like the variable names are wrong, but this makes the code below easier to read.
        target_links = @redis.smembers "webmention::#{site_id}::source::#{link.type}::#{link.is_direct}::#{link.source}"
        source_links = @redis.smembers "webmention::#{site_id}::target::#{link.target}"

        notifications = NotificationQueue.generate_notifications(target_links, source_links)

        # Remove the mentions that were include in this notification
        links.each do |id|
          link = Link.get(id)
          @redis.srem "webmention::#{site_id}::source::#{link.type}::#{link.is_direct}::#{link.source}", id
          @redis.srem "webmention::#{site_id}::target::#{link.target}", id

          if @redis.scard "webmention::#{site_id}::source::#{link.type}::#{link.is_direct}::#{link.source}" == 0
            @redis.del "webmention::#{site_id}::source::#{link.type}::#{link.is_direct}::#{link.source}"
          end
          if @redis.scard "webmention::#{site_id}::target::#{link.target}" == 0
            @redis.del "webmention::#{site_id}::target::#{link.target}"
          end
        end

        @redis.zrem "webmention::#{site_id}::timers", link_id

        notifications.each do |notification|
          puts ""
          puts "\t#{notification.url}"
          puts "\t#{notification.text}"
          puts ""
          NotificationQueue.send_notification site, "[mention] #{notification.text} #{notification.url}"
        end

        # If there are no other timers set for this account, remove it from the queue
        if @redis.zcard "webmention::#{site_id}::timers" == 0
          @redis.srem "webmention::queued", site_id
        end

      end
    end
  end

  def self.generate_notifications(target_links, source_links)
    notifications = []

    # Process the one with more mentions
    if target_links.length > source_links.length
      # One source linked to many targets.
      # Most often this is when someone writes a blog post that references a bunch
      # of wiki pages.

      links = []
      targets = target_links.map{|id|
        Link.get(id)
      }.uniq

      links = targets.map{|link|
        link.id
      }

      source_authors = targets.map{|link|
        link.author_text
      }.uniq
      source_authors_html = targets.map{|link|
        link.author_html
      }.uniq
      text = source_authors.join_with_and
      html = source_authors_html.join_with_and

      text += " posted "
      html += " posted "

      text += targets.map{|link|
        if link.type and link.type != "link" and !link.name.blank?
          "#{link.type.with_indefinite_article}: \"#{link.name_truncated}\" #{link.href}"
        elsif !link.name.blank?
          "\"#{link.name_truncated}\" #{link.href}"
        elsif link.type and link.type != "link"
          "#{link.type.with_indefinite_article} #{link.href}"
        else
          link.href
        end
      }.uniq.join_with_and
      html += targets.map{|link|
        if link.type and link.type != "link" and !link.name.blank?
          "#{link.type.with_indefinite_article}: <a href=\"#{link.href}\">#{link.name_truncated}</a>"
        elsif !link.name.blank?
          "<a href=\"#{link.href}\">#{link.name_truncated}</a>"
        elsif link.type and link.type != "link"
          "#{link.type.with_indefinite_article} <a href=\"#{link.href}\">#{link.href}</a>"
        else
          "<a href=\"#{link.href}\">#{link.href}</a>"
        end
      }.uniq.join_with_and

      text += " that linked to "
      html += " that linked to "

      text += targets.map{|link|
        link.target
      }.uniq.join_with_and
      html += targets.map{|link|
        "<a href=\"#{link.target}\">#{link.target}</a>"
      }.uniq.join_with_and

      puts "================"
      puts "Notification: #{text}"
      puts "================"

      notification = Notification.new :account => site.account, :site => site
      notification.text = text
      notification.html = html
      notification.links = targets
      notification.token = SecureRandom.urlsafe_base64 16
      notification.save
      notifications << notification

      links = target_links
    else
      # Many sources linked to one target.
      # Most often this is when many "likes" are received in a row, or when bridgy
      # sends the flood of invites for a POSSE'd event.
      source_types = {}
      links = []

      # puts "source links:"
      # jj source_links
      # puts "target links:"
      # jj target_links

      # The source links may be different "types" of objects, such as a "like" vs "reply",
      # or an "RSVP yes" vs "RSVP no". We want to generate notifications for each
      # type of interaction, not collapsing webmentions of different types.
      # For example, "X was invited to Y" and "W RSVPd to Y" should be separate notifications.
      source_links.each{|id|
        link = Link.get(id)
        if source_types[link.type].nil?
          source_types[link.type] = []
        end
        source_types[link.type] << link
      }

      # puts "source types:"
      # jj source_types

      # Process each type of source separately
      source_types.each do |type, source_links|
        notification = Notification.new :account => site.account, :site => site

        notification.links = source_links

        source_authors = source_links.map{|link|
          links << link.id
          link.author_text
        }.uniq
        source_authors_html = source_links.map{|link|
          links << link.id
          link.author_html
        }.uniq
        text = source_authors.join_with_and
        html = source_authors_html.join_with_and

        case type
        when "rsvp-yes"
          action = "RSVPd yes to"
          action += " an event that linked to" unless is_direct
        when "rsvp-no"
          action = "RSVPd no to"
          action += " an event that linked to" unless is_direct
        when "rsvp-maybe"
          action = "RSVPd maybe to"
          action += " an event that linked to" unless is_direct
        when "invite"
          if source_authors.length == 1
            verb = "was"
          else
            verb = "were"
          end
          action = "#{verb} invited to"
          action += " an event that linked to" unless is_direct
        when "like"
          action = "liked"
          action += " a post that linked to" unless is_direct
        when "repost"
          action = "reposted"
          action += " a post that linked to" unless is_direct
        when "reply"
          action = "commented on"
          action += " a post that linked to" unless is_direct
        when "link"
          action = "wrote a post that linked to"
        else
          action = "wrote a post that linked to"
        end

        text += " #{action} "
        html += " #{action} "

        text += target_links.map{|id|
          link = Link.get(id)
          if !link.page.type.blank? and !link.page.name.blank?
            "#{link.page.type.with_indefinite_article}: \"#{link.page.name_truncated}\" #{link.page.href}"
          elsif !link.page.name.blank?
            "\"#{link.page.name_truncated}\" #{link.page.href}"
          elsif !link.page.type.blank?
            "#{link.page.type.with_indefinite_article} #{link.page.href}"
          else
            link.page.href
          end
        }.uniq.join_with_and

        html += target_links.map{|id|
          link = Link.get(id)
          if !link.page.type.blank? and !link.page.name.blank?
            "#{link.page.type.with_indefinite_article}: \"<a href=\"#{link.page.href}\">#{link.page.name_truncated}</a>\""
          elsif !link.page.name.blank?
            "\"<a href=\"#{link.page.href}\">#{link.page.name_truncated}</a>\""
          elsif !link.page.type.blank?
            "<a href=\"#{link.page.href}\">#{link.page.type.with_indefinite_article}</a>"
          else
            "<a href=\"#{link.page.href}\">#{link.page.href}</a>"
          end
        }.uniq.join_with_and

        puts "#{action}"
        puts target_links.inspect

        notification.text = text
        notification.html = html
        notification.token = SecureRandom.urlsafe_base64 16
        notification.save

        notifications << notification
      end
    end

    notifications
  end

  def self.send_notification(site, message)
    puts "Sending notification: #{message}"

    if !site.account.zenircbot_uri.empty? and !site.irc_channel.empty?

      uri = "#{site.account.zenircbot_uri}#{URI.encode_www_form_component site.irc_channel}"

      begin
        puts RestClient.post uri, {
          message: message
        }
      rescue
        # ignore errors sending to IRC
      end
    end

    if !site.account.xmpp_user.empty? and !site.account.xmpp_to.empty? and site.xmpp_notify
      jabber = Jabber::Client::new(Jabber::JID::new(site.account.xmpp_user))
      begin
        jabber.connect
        if site.account.xmpp_password.empty?
          jabber.auth_anonymous
        else
          jabber.auth(site.account.xmpp_password)
        end
        jabbermsg = Jabber::Message::new(site.account.xmpp_to, message)
        jabbermsg.set_type(:headline)
        jabber.send(jabbermsg)
        jabber.close
        puts "Sent Jabber message"
      rescue
        # ignore errors for jabber
      end
    end

  end

end
