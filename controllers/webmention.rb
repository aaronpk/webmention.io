class Controller < Sinatra::Base

  # Chances are some people will click the links in the href tags, so show a nice message here
  get '/:username/xmlrpc' do |username|
    title "Hosted Pingback Service"
    @username = username
    error 200, erb(:endpoint)
  end

  get '/:username/webmention' do |username|
    title "Hosted Webmention Service"
    @username = username
    error 200, erb(:endpoint)
  end

  # Webmention status page
  get '/:username/webmention/:token' do |username, token|

    status = @redis.get "webmention:status:#{token}"

    if status
      json_response 200, JSON.parse(status)
    else
      json_response 404, {
        :error => 'not_found',
      }
    end
  end

  # Receive Webmentions
  post '/:username/webmention' do |username|

    validate_parameters params[:source], params[:target]
    
    # First check that the domain of the target exists on this account
    # Special case for the few accounts that existed before all accounts were named the domain name
    account = Account.first(:conditions => ['domain = ? OR username = ?', username, username])

    if account.nil?
      json_response 404, {
        :error => 'not_found',
        :error_description => "account #{username} not found"
      }
    end

    target_uri = URI.parse(URI.escape(params[:target]))
    target_domain = target_uri.host
    
    # Check that the domain of the target URL is a registered site on this account
    site = Site.first :account => account, :domain => target_domain
    
    if site.nil?
      json_response 404, {
        :error => 'invalid_target',
        :error_description => "target domain not found on this account"
      }
    end
    
    process_webmention(username, 'account')

  end
  
  post '/d/:domain/webmention' do |domain|
    validate_parameters params[:source], params[:target]

    # First check that the domain of the target exists
    site = Site.first :domain => domain
    if site.nil?
      json_response 404, {
        :error => 'not_found',
        :error_description => "site #{domain} not found"
      }
    end

    target_uri = URI.parse(URI.escape(params[:target]))
    target_domain = target_uri.host

    # Check that the target domain of the webmention matches the domain of the endpoint
    if target_domain != site.domain
      json_response 400, {
        :error => 'invalid_target',
        :error_description => "Target domain (#{target_domain}) does not match the domain of this webmention endpoint (#{domain})"
      }      
    end

    process_webmention(site.account.username, 'site')

  end
  
  def process_webmention(username, endpoint_type)
    hash = OpenSSL::Digest::MD5.hexdigest("s=#{params[:source]};t=#{params[:target]}")

    if @redis.get "webmention:ratelimit:#{hash}"
      json_response 429, {
        :error => 'rate_limit_exceeded',
        :error_description => 'Only one request per source and target combination is allowed every 30 seconds'
      }
    end

    @redis.setex "webmention:ratelimit:#{hash}", 30, 1

    token = SecureRandom.urlsafe_base64 15
    status_url = "#{SiteConfig.base_url}/#{username}/webmention/#{token}"

    # Swap Twitter URLs for Bridgy proxy URLs
    if m=params[:source].match(/https?:\/\/(?:www\.)?twitter\.com\/(.+)\/status(?:es)?\/([0-9]+)/)
      params[:source] = "https://brid.gy/post/twitter/#{m[1]}/#{m[2]}"
    end

    puts "#{DateTime.now} WM: source=#{params[:source]} target=#{params[:target]}#{params[:code] ? ' private' : ''} ip=#{request.ip} status=#{status_url}"

    begin
      result = process_mention(username, params[:source], params[:target], 'webmention', token, params[:code], params[:debug], endpoint_type)
    rescue => e
      puts "!!!!!!!!!!!!!!!!!!!!!"
      puts "INTERNAL SERVER ERROR"
      puts e.inspect
      puts e.backtrace
      json_response 500, {
        :error => 'internal_server_error',
        :error_description => e.message
      }
    end

    case result
    when 'queued'
      code = accept_html ? 303 : 201 # send browsers a 303 so they get redirected to the status page
      json_response code, {
        :status => 'queued',
        :summary => 'Webmention was queued for processing',
        :location => status_url,
        :source => params[:source],
        :target => params[:target]
      }, {
        'Location' => status_url
      }
    when 'success'
      json_response 200, {
        :status => 'success',
        :summary => 'Webmention was successful'
      }
    when 'source_not_found'
      json_response 400, {
        :error => result,
        :error_description => 'The source URI does not exist'
      }
    when 'invalid_target'
      json_response 400, {
        :error => result,
        :error_description => 'The target is not a valid URI'
      }
    when 'target_not_found'
      json_response 400, {
        :error => result,
        :error_description => 'The target URI does not exist'
      }
    when 'target_not_supported'
      json_response 400, {
        :error => result,
        :error_description => 'The specified target URI is not a Webmention-enabled resource'
      }
    when 'no_link_found'
      json_response 400, {
        :error => result,
        :error_description => 'The source URI does not contain a link to the target URI'
      }
    end

  end

  # Receive Pingbacks
  post '/:username/xmlrpc' do |username|
    
    account = Account.first :username => username
    
    if account.nil?
      rpc_error 404, 0, "Not found"
    end
    
    if account.pingback_enabled == false
      rpc_error 401, 0, "Inactive account"
    end

    #puts "RECEIVED PINGBACK REQUEST"
    utf8 = request.body.read.force_encoding "UTF-8"
    # puts utf8

    if utf8.valid_encoding?
      xml = utf8
    else
      puts "Invalid string encoding"
      rpc_error 400, 0, "Invalid string encoding"
    end
    begin
      method, arguments = XMLRPC::Marshal.load_call(xml)
    rescue
      rpc_error 400, 0, "Invalid request"
    end

    method.gsub! /\./, '_'

    if method == 'pingback_ping'
      content_type("text/xml", :charset => "utf-8")
      source, target = arguments

      puts "#{DateTime.now} PB s=#{source} t=#{target} ip=#{request.ip}"
      validate_parameters source, target

      token = SecureRandom.urlsafe_base64 15

      begin
        result = process_mention(username, source, target, 'pingback', token, nil)
      rescue => e
        puts "!!!!!!!!!!!!!!!!!!!!!"
        puts "INTERNAL SERVER ERROR"
        puts e.inspect
        rpc_error 500, 0, "Internal Server Error: #{e.message}"
      end

      case result
      when 'queued'
        status_url = "#{SiteConfig.base_url}/#{username}/webmention/#{token}"
        rpc_respond 200, "Pingback was queued for processing: #{status_url}"
      when 'success'
        rpc_respond 200, "Pingback from #{source} to #{target} was successful! Keep the web talking!"
      when 'source_not_found'
        rpc_error 200, 0x0010, "The source URI does not exist"
      when 'invalid_target'
        rpc_error 200, 0x0021, "The target is not a valid URI"
      when 'target_not_found'
        rpc_error 200, 0x0020, "The target URI does not exist"
      when 'target_not_supported'
        rpc_error 200, 0x0021, "The specified target URI is not a Pingback-enabled resource"
      when 'no_link_found'
        rpc_error 200, 0x0011, "There appears to be no link to this page!"
      when 'already_registered'
        rpc_error 200, 0x0030, "The pingback has already been registered"
      end
    else
      rpc_error 404, 0, "Not Found"
    end
  end

  def validate_parameters(source, target)
    if source.empty? or target.empty?
      json_response 400, {
        :error => 'invalid_request',
        :error_description => 'source or target were missing'
      }
    end

    begin
      source = URI.parse(URI.escape(source))
      target = URI.parse(URI.escape(target))
      if source.host.nil? or target.host.nil?
        raise "missing host"
      end
      if !['http','https'].include?(source.scheme) or !['http','https'].include?(target.scheme)
        raise "invalid protocol"
      end
    rescue => e
      json_response 400, {
        :error => 'invalid_request',
        :error_description => "source or target were invalid",
        :error_details => e.message
      }
    end
  end

  def process_mention(username, source, target, protocol, token, code, debug=false, endpoint_type='account')
    WebmentionProcessor.update_status @redis, token, {
      :status => 'pending',
      :source => source,
      :target => target,
      :private => code ? true : false,
      :summary => "The webmention is currently being processed",
      :data => {}
    }

    if debug
      WebmentionProcessor.new.process_mention username, source, target, protocol, token, code, endpoint_type
    else
      WebmentionProcessor.new.async.perform(
        :username => username,
        :source => source,
        :target => target,
        :protocol => protocol,
        :token => token,
        :code => code,
        :endpoint_type => endpoint_type
      )

      'queued'
    end
  end
end
