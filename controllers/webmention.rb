class Controller < Sinatra::Base

  # Chances are some people will click the links in the href tags, so show a nice message here
  get '/:username/xmlrpc' do |username|
    title "Hosted Pingback Service"
    error 404, erb(:about)
  end

  get '/:username/webmention' do |username|
    title "Hosted Webmention Service"
    error 404, erb(:about)
  end

  # Receive Webmentions
  post '/:username/webmention' do |username|

    puts "RECEIVED WEBMENTION REQUEST"

    begin
      result = process_mention(username, params[:source], params[:target], 'webmention', params[:debug])
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
      json_response 202, {
        :result => 'Webmention was queued for processing'
      }      
    when 'success'
      json_response 202, {
        :result => 'Webmention was successful'
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
    when 'already_registered'
      json_response 400, {
        :error => result,
        :error_description => 'The specified Webmention has already been registered'
      }
    end
  end

  # Receive Pingbacks
  post '/:username/xmlrpc' do |username|

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
    puts "Method: #{method} Args: #{arguments}"

    if method == 'pingback_ping'
      content_type("text/xml", :charset => "utf-8")
      source, target = arguments

      begin
        result = process_mention(username, source, target, 'pingback')
      rescue => e
        puts "!!!!!!!!!!!!!!!!!!!!!"
        puts "INTERNAL SERVER ERROR"
        puts e.inspect
        rpc_error 500, 0, "Internal Server Error: #{e.message}"
      end

      case result
      when 'queued'
        rpc_respond 200, "Pingback was queued for processing"
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

  def process_mention(username, source, target, protocol, debug=false)
    if debug
      WebmentionProcessor.new.process_mention username, source, target, protocol
    else
      WebmentionProcessor.new.async.perform(
        :username => username,
        :source => source,
        :target => target,
        :protocol => protocol
      )

      'queued'
    end
  end
end
