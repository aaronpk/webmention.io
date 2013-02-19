class Controller < Sinatra::Base

  # Forward pingbacks to webmentions
  post '/webmention' do

    # Check for a valid "forward" parameter
    if !params[:forward]
      rpc_error 404, 0, "Not Found"
    end

    # Validate the url checking for http or https protocol
    uri = URI.parse params[:forward]

    if !['http','https'].include? uri.scheme
      rpc_error 400, 0, "Invalid 'forward' parameter"
    end

    xml = request.body.read.force_encoding "UTF-8"
    if !xml.valid_encoding?
      rpc_error 400, 0, "Invalid string encoding"
    end
    method, arguments = XMLRPC::Marshal.load_call(xml)

    if method != "pingback.ping"
      rpc_error 404, 0, "Method not found"
    end

    source, target = arguments

    begin
      response = RestClient.post params[:forward], {
        source: source,
        target: target
      }, {
        'Accept' => 'application/json'
      }
    rescue RestClient::Exception => e
      create_rpc_error e.response
    end

    # Find out if the request succeeded or failed

    if response.code == 202
      begin
        # Attempt to parse the JSON body
        json = JSON.parse response.body
        if json and json.class == Hash and json['result']
          rpc_respond 200, json['result']
        end
      rescue
        # fall through
      end
      # If the body was not JSON, or did not contain a message, return a generic message
      rpc_respond 200, "Pingback from #{source} to #{target} was successful!"
    else
      create_rpc_error response.body
    end
  end

  def create_rpc_error(body)
    begin
      # Attempt to parse the JSON body
      json = JSON.parse body
      code = 0
      case json['error']
      when 'source_not_found'
        code = 0x0010
      when 'target_not_found'
        code = 0x0020
      when 'target_not_supported'
        code = 0x0021
      when 'already_registered'
        code = 0x0030
      when 'no_link_found'
        code = 0x0011
      end
      rpc_error 400, code, json['error']
    rescue
      # If the body was not JSON, return a generic error
      rpc_error 400, 0, "Unknown Error"
    end
  end


  # Chances are some people will click the links in the href tags, so show a nice message here
  get '/:username/xmlrpc' do |username|
    title "Hosted Pingback Service"
    error 404, erb(:about)
  end

  # Web Hooks

  # XML RPC
  post '/:username/xmlrpc' do |username|

    puts "RECEIVED PINGBACK REQUEST"
    utf8 = request.body.read.force_encoding "UTF-8"
    puts utf8

    @target_account = Account.first :username => username

    if @target_account.nil?
      rpc_error 404, 0, "Not Found"
    end

    if utf8.valid_encoding?
      xml = utf8
    else
      rpc_error 400, 0, "Invalid string encoding"
    end
    method, arguments = XMLRPC::Marshal.load_call(xml)

    method.gsub! /\./, '_'
    puts "Method: #{method} Args: #{arguments}"

    if respond_to?(method)
      content_type("text/xml", :charset => "utf-8")
      send method, arguments
    else
      rpc_error 404, 0, "Not Found"
    end
  end

  def pingback_ping(args)
    source, target = args

    puts "Verifying link exists from #{source} to #{target}"

    target_domain = URI.parse(target).host

    return rpc_error 200, 0, "Malformed target URI" if target_domain.nil?

    site = Site.first_or_create :account => @target_account, :domain => target_domain
    page = Page.first_or_create({:site => site, :href => target}, {:account => @target_account})
    link = Link.first_or_create(:page => page, :href => source)

    if link[:verified]
      rpc_error 200, 0x0030, "The pingback has already been registered"
    end

    agent = Mechanize.new {|agent|
      agent.user_agent_alias = "Mac Safari"
    }
    scraper = agent.get source

    valid = scraper.link_with(:href => target) != nil

    if !site.account.zenircbot_uri.empty? and !site.irc_channel.empty? and valid
      message = "[pingback] #{source} linked to #{target}"

      uri = "#{site.account.zenircbot_uri}#{URI.encode_www_form_component site.irc_channel}"

      begin
        puts RestClient.post uri, {
          message: message
        }
      rescue 
        # noop
      end
    end

    if valid
      link.verified = true
      link.save
      rpc_respond 200, "Pingback from #{source} to #{target} was successful! Keep the web talking!"
    else
      rpc_error 200, 0x0011, "There appears to be no link to this page!"
    end

    # See http://www.hixie.ch/specs/pingback/pingback for a list of error codes to return
  end

end