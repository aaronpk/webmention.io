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
      result = process_mention(username, params[:source], params[:target], 'webmention')
    rescue => e
      puts "!!!!!!!!!!!!!!!!!!!!!"
      puts "INTERNAL SERVER ERROR"
      puts e.inspect
      json_response 500, {
        :error => 'internal_server_error',
        :error_description => e.message
      }
    end

    case result
    when 'success'
      json_response 202, {
        :result => 'WebMention was successful'
      }
    when 'source_not_found'
      json_response 400, {
        :error => result,
        :error_description => 'The source URI does not exist'
      }
    when 'target_not_found'
      json_response 400, {
        :error => result,
        :error_description => 'The target URI does not exist'
      }
    when 'target_not_supported'
      json_response 400, {
        :error => result,
        :error_description => 'The specified target URI is not a WebMention-enabled resource'
      }
    when 'no_link_found'
      json_response 400, {
        :error => result,
        :error_description => 'The source URI does not contain a link to the target URI'
      }
    when 'already_registered'
      json_response 400, {
        :error => result,
        :error_description => 'The specified WebMention has already been registered'
      }
    end
  end

  # Receive Pingbacks
  post '/:username/xmlrpc' do |username|

    puts "RECEIVED PINGBACK REQUEST"
    utf8 = request.body.read.force_encoding "UTF-8"
    # puts utf8

    if utf8.valid_encoding?
      xml = utf8
    else
      pust "Invalid string encoding"
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
      when 'success'
        rpc_respond 200, "Pingback from #{source} to #{target} was successful! Keep the web talking!"
      when 'source_not_found'
        rpc_error 200, 0x0010, "The source URI does not exist"
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

  # Handles actually verifying source links to target, returning the list of errors based on the webmention errors
  def process_mention(username, source, target, protocol)

    puts "Verifying link exists from #{source} to #{target}"

    target_account = Account.first :username => username
    return 'target_not_found' if target_account.nil?

    begin
      target_domain = URI.parse(target).host 
    rescue
      return 'target_not_found' if target_domain.nil?
    end
    return 'target_not_found' if target_domain.nil?

    site = Site.first_or_create :account => target_account, :domain => target_domain
    page = Page.first_or_create({:site => site, :href => target}, {:account => target_account})
    link = Link.first_or_create(:page => page, :href => source)

    already_registered = link[:verified]

    agent = Mechanize.new {|agent|
      agent.user_agent_alias = "Mac Safari"
    }
    begin
      scraper = agent.get source
    rescue
      return 'source_not_found' if scraper.nil?
    end

    valid = scraper.link_with(:href => target) != nil

    return 'no_link_found' if !valid

    # Only send notifications about new webmentions
    if !already_registered
      message = "[mention] #{source} linked to #{target} (#{protocol})"
      puts "Sending notification #{message}"

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

    else
      puts "Already sent notification"
    end # notification

    parsed = false
    begin
      parsed = JSON.parse RestClient.get "#{SiteConfig.mf2_parser}?source=#{URI.encode_www_form_component source}"

      #link.html = scraper.body
      link.author_name = parsed['author']['name']
      link.author_url = parsed['author']['url']
      link.author_photo = parsed['author']['photo']
      link.name = parsed['name']
      link.content = parsed['content']
      link.published = DateTime.parse(parsed['published'])
      link.published_ts = parsed['published_ts']
    rescue
      # Ignore errors trying to parse for upgraded microformats
    end

    # Publish on Redis for realtime comments
    if @redis && parsed
      @redis.publish "webmention.io::#{target}", {
        type: 'webmention',
        element_id: "external_#{source.gsub(/[\/:\.]+/, '_')}",
        author: parsed['author'],
        name: parsed['name'],
        content: parsed['content'],
        url: source,
        published: parsed['published'],
        published_ts: parsed['published_ts']
      }.to_json
    end

    link.verified = true
    link.save
    return 'success'
  end

end
