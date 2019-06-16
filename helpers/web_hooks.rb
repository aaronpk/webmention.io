class WebHooks

  def self.notify(site, link, source, target, _private)
    # If a callback URL is defined for this site, send to the callback now
    if !site.callback_url.blank?
      begin
        puts "Sending to callback URL: #{site.callback_url}"

        jf2 = Formats.build_jf2_from_link(link)

        data = {
          secret: site.callback_secret,
          source: source,
          target: target,
          private: _private,
          post: jf2
        }

        RestClient::Request.execute(:method => :post,
          :url => site.callback_url,
          :payload => data.to_json,
          :headers => {:content_type => 'application/json'},
          :ssl_ca_file => './helpers/ca-bundle.crt')
        puts "... success!"
      rescue => e
        puts "Failed to send to callback URL #{site.callback_url} #{e.inspect}"
      end
    end
  end

  def self.deleted(site, source, target, _private)
    # If a callback URL is defined for this site, send the delete notification to the callback now
    if !site.callback_url.blank?
      begin
        puts "Sending DELETE to callback URL: #{site.callback_url}"

        data = {
          secret: site.callback_secret,
          source: source,
          target: target,
          private: _private,
          deleted: true
        }

        RestClient::Request.execute(:method => :post,
          :url => site.callback_url,
          :payload => data.to_json,
          :headers => {:content_type => 'application/json'},
          :ssl_ca_file => './helpers/ca-bundle.crt')
        puts "... success!"
      rescue => e
        puts "Failed to send to callback URL #{site.callback_url} #{e.inspect}"
      end
    end
  end

end
