class NotificationQueue

  def self.queue_notification(link, message)

    self.send_notification link, message

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