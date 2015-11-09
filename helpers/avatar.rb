class Avatar
  def self.get_avatar_archive_url(original_url)
    if SiteConfig.ca3db.api_endpoint.blank?
      return original_url
    end
      
    begin
      response = RestClient.post SiteConfig.ca3db.api_endpoint, {
        key_id: SiteConfig.ca3db.key_id,
        secret_key: SiteConfig.ca3db.secret_key,
        region: SiteConfig.ca3db.region,
        bucket: SiteConfig.ca3db.bucket,
        url: original_url
      }.to_json, {
        content_type: :json,
        'x-api-key' => SiteConfig.ca3db.api_key
      }
      if response
        data = JSON.parse response
        if data['url']
          archive_url = data['url'].sub SiteConfig.ca3db.s3_url, "#{SiteConfig.base_url}/avatar"
          puts "Archived avatar: #{original_url} #{archive_url}"
          return archive_url
        end
      end
      return original_url
    rescue => e
      puts "There was an error saving to S3"
      puts e.inspect
      return original_url
    end
  end
end
