class Controller < Sinatra::Base

  # Chances are some people will click the links in the href tags, so show a nice message here
  get '/:username/xmlrpc' do |username|
    title "Hosted Pingback Service"
    error 404, erb(:about)
  end

  # Web Hooks

  # XML RPC
  post '/:username/xmlrpc' do |username|

    puts "RECEIVED PINGBACK REQUEST"

    @target_account = Account.first :username => username

    if @target_account.nil?
      rpc_error 404, 0, "Not Found"
    end

    utf8 = request.body.read.force_encoding "UTF-8"
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