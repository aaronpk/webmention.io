class XRay
  def self.parse(url, target, html=false)
    if SiteConfig.xray_server.blank?
      return nil
    end
      
    begin
      user_agent = SiteConfig.base_url.gsub /^https?:\/\//, ''
      if html
        response = RestClient.post SiteConfig.xray_server, {
          url: url,
          target: target,
          html: html
        }, {
          :user_agent => user_agent
        }
      else
        response = RestClient.get SiteConfig.xray_server, {
          params: {
            url: url,
            target: target
          },
          :user_agent => user_agent
        }
      end
      if response
        data = JSON.parse response
        if data['data']
          return data['data']
        elsif !data['error'].blank?
          return data['error']
        end
      end
      return nil
    rescue => e
      begin
        if e.response.class == String
          data = JSON.parse e.response
          if !data['error'].blank?
            return data['error']
          else
            return nil
          end
        else
          puts "There was an error parsing the source URL: #{e.inspect}"
          return nil
        end
      rescue => e
        puts "There was an error parsing the source URL: #{e.inspect}"
        return nil
      end
    end
  end
end
