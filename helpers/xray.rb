class XRay
  def self.parse(url, target, html=false)
    if SiteConfig.xray_server.blank?
      return nil
    end
      
    begin
      if html
        response = RestClient.post SiteConfig.xray_server, {
          url: url,
          target: target,
          html: html
        }
      else
        response = RestClient.get SiteConfig.xray_server, {
          params: {
            url: url,
            target: target
          }
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
      puts "There was an error parsing the source URL"
      puts e.inspect
      return nil
    end
  end
end
