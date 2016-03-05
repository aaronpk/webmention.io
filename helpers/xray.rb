class XRay
  def self.parse(url, target=nil, html=false)
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
            target: target,
            timeout: 12
          },
          :user_agent => user_agent
        }
      end
      if response
        data = JSON.parse response
        if data['data']
          return data['data']
        elsif !data['error'].blank?
          return XRayError.new (data['error'] == 'unknown' ? 'error' : data['error']), data['error_description']
        end
      end
      return nil
    rescue => e
      begin
        if e.class == Exception and e.response.class == String
          data = JSON.parse e.response
          if !data['error'].blank?
            return XRayError.new (data['error'] == 'unknown' ? 'error' : data['error']), data['error_description']
          else
            return nil
          end
        else
          puts "There was an error parsing the source URL: #{e.inspect}"
          return XRayError.new 'parse_error', "There was an error parsing the source URL"
        end
      rescue => e
        puts "There was an error parsing the source URL: #{e.inspect}"
        return XRayError.new 'parse_error', "There was an error parsing the source URL"
      end
    end
  end
end

class XRayError
  attr_accessor :error
  attr_accessor :error_description

  def initialize(error, error_description)
    @error = error
    @error_description = error_description
  end
end
