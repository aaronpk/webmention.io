class NotificationQueue

  def self.queue_notification(link, message)
    #self.send_notification link, message

    if !@redis
      puts "Connecting to Redis"
      @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port
    end

    account_id = link.page.account.id

    # Add an entry indicating this account has queued mentions
    @redis.sadd "webmention::queued", account_id

    # Create clusters for source and target, adding the notification to both
    @redis.sadd "webmention::#{account_id}::source::#{link.source}", link.id
    @redis.sadd "webmention::#{account_id}::target::#{link.target}", link.id

    # Set a timer for this notification for 60 seconds from now
    @redis.zadd "webmention::#{account_id}::timers", (Time.now.to_i+60), link.id
  end

  def self.process_notifications
    @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port

    # Get a list of all the accounts that have pending mentions
    accounts = @redis.smembers "webmention::queued"
    accounts.each do |account_id|
      account = Account.first :id => account_id
      puts "Processing account: #{account_id} #{account.domain}"

      # Find any timers that have expired
      timers = @redis.zrangebyscore "webmention::#{account_id}::timers", 0, Time.now.to_i
      timers.each do |link_id|
        link = Link.first :id => link_id
        puts "Processing timer #{link_id} (source: #{link.source} target: #{link.target}"

        # Get the members of both the source and target lists
        source_links = @redis.smembers "webmention::#{account_id}::source::#{link.source}"
        target_links = @redis.smembers "webmention::#{account_id}::target::#{link.target}"

        # Process the one with more mentions
        if source_links.length > target_links.length
          text = source_links.map{|id| link = Link.first(:id => id).source}.uniq.join(" and ")
          text += " linked to "
          target = source_links.map{|id| link = Link.first(:id => id).target}.uniq.join(" and ")
          text += target

          links = source_links
        else
          source = target_links.map{|id| link = Link.first(:id => id).source}.uniq.join(" and ")
          text = "#{source} linked to "
          text += target_links.map{|id| link = Link.first(:id => id).target}.uniq.join(" and ")

          links = target_links
        end

        # Remove the mentions that were include in this notification
        links.each do |id|
          link = Link.first(:id => id)
          @redis.srem "webmention::#{account_id}::source::#{link.source}", id
          @redis.srem "webmention::#{account_id}::target::#{link.target}", id

          if @redis.scard "webmention::#{account_id}::source::#{link.source}" == 0
            @redis.del "webmention::#{account_id}::source::#{link.source}"
          end
          if @redis.scard "webmention::#{account_id}::target::#{link.target}" == 0
            @redis.del "webmention::#{account_id}::target::#{link.target}"
          end
        end

        puts ""
        puts "\t#{text}"
        puts ""

        @redis.zrem "webmention::#{account_id}::timers", link_id
      end
    end
  end

  def self.send_notification(link, message)
    site = link.page.site

    puts "SENDING NOTIFICATION: #{message}"

    if !site.account.zenircbot_uri.empty? and !site.irc_channel.empty? and valid

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