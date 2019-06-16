class Controller < Sinatra::Base
  before do
    @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port
  end

  def require_login
    if session[:user_id].nil?
      redirect "/"
    end

    @user = Account.get session[:user_id]
    if @user.nil?
      puts "No user found. Redirecting."
      redirect "/"
    end
  end

  get '/?' do
    title "Webmention.io"
    erb :index
  end

  get '/dashboard/?' do
    require_login

    if @user.token.nil?
      @user.token = SecureRandom.urlsafe_base64 16
      @user.save
    end

    opts = {
      :verified => true,
      :deleted => false,
      :order => [],
      :limit => 40
    }
    opts[:order] << :created_at.send(:desc)

    @recent = @user.links.all(opts)

    title "Dashboard"
    erb :dashboard
  end

  get '/settings/?' do
    require_login

    if @user.token.nil?
      @user.token = SecureRandom.urlsafe_base64 16
      @user.save
    end

    title "Settings"
    erb :settings
  end

  get '/settings/webhooks' do
    require_login

    if @user.token.nil?
      @user.token = SecureRandom.urlsafe_base64 16
      @user.save
    end

    title "Web Hook Settings"
    erb :webhooks
  end

  get '/settings/blocks' do
    require_login

    @blocks = @user.blocks.all

    title "Blocklist Settings"
    erb :blocklists
  end

  get '/delete/?' do
    require_login

    opts = {
      href: params[:source],
      deleted: false
    }
    @links = @user.links.all opts

    if params[:id]
      @link = @user.links.first({ id: params[:id] })
      @domain = @link.domain
    else
      @link = nil
      uri = URI params[:source]
      @domain = uri.host
    end

    @domain_count = @user.sites.links.all({
      domain: @domain,
      unique: true
    }).count

    title "Delete"
    erb :delete
  end

  post '/delete/?' do
    require_login
    verify_csrf '/delete'

    # Delete this single webmention
    if params[:id]
      # Check that this ID belongs to this user
      link = Link.get params[:id]
      if link && link.site.account_id = session[:user_id]
        # Mark this particular webmention as deleted
        link.deleted = true
        link.save

        # Add this source URL to the blacklist for just this site
        blacklist = Blacklist.new
        blacklist.site = link.site
        blacklist.source = link.href
        blacklist.created_at = Time.now
        blacklist.save

        # Notify the callback URL
        WebHooks.deleted link.site, link.href, link.page.href, link.is_private

      else
        redirect "/dashboard"
      end
    end

    # Delete all webmentions from this source URL
    if params[:source]
      # Mark each webmention as deleted
      opts = {
        href: params[:source],
        deleted: false
      }
      links = @user.links.all opts
      links.each do |link|
        link.deleted = true
        link.save

        # Notify the callback URL
        WebHooks.deleted link.site, link.href, link.page.href, link.is_private
      end
      # Add this source URL to the blacklist for each site
      @user.sites.each do |site|
        blacklist = Blacklist.new
        blacklist.site = site
        blacklist.source = params[:source]
        blacklist.created_at = Time.now
        blacklist.save
      end
    end

    if params[:domain]
      links = @user.links.all({
        domain: params[:domain]
      }).update({
        deleted: true
      })
      block = Block.new
      block.account = @user
      block.created_at = Time.now
      block.domain = params[:domain]
      block.save
    end

    redirect "/dashboard"
  end

  post '/unblock' do
    require_login

    block = @user.blocks.first({ domain: params[:domain] })
    if block
      block.destroy
    end

    redirect '/settings/blocks'
  end

  post '/webhook/configure' do
    require_login

    site = Site.first :id => params[:site_id]
    if site
      site.callback_url = params[:callback_url]
      site.callback_secret = params[:callback_secret]
      site.archive_avatars = params[:archive_avatars] ? 1 : 0
      site.public_access = params[:require_api_key] ? 0 : 1
      site.save
    end

    redirect "/settings"
  end

  # Authentication

  get '/auth/failure' do
    @message = "The authentication provider replied with an error: #{params['message']}"
    title "Error"
    erb :error
  end

  get '/reset' do
    session.clear
    title "Session"
    erb :session
  end

  # Helpers

  def rpc_respond(code, string)
    error code, XMLRPC::Marshal.dump_response(string)
  end

  def rpc_error(code, error, string)
    error code, XMLRPC::Marshal.dump_response(XMLRPC::FaultException.new(error.to_i, string))
  end

  def api_response(format, code, data)
    if format == 'json' || format == 'jf2'
      json_response(code, data)
    elsif format == 'atom'
      xml_response(code, data)
    end
  end

  def json_error(code, data)
    json_response(code, data)
  end

  def json_respond(code, data)
    json_response(code, data)
  end

  def json_response(code, data, headers={})
    # Check if the request has an HTTP Accept header requesting HTML
    if params[:jsonp]
      string = "#{params[:jsonp]}(#{data.to_json})"
      content_type = 'text/javascript'
    elsif accept_html
      title "Webmention.io"
      @data = data
      string = erb :html_response
      content_type = 'text/html'
    else
      string = data.to_json
      content_type = 'application/json'
    end

    halt code, {
        'Content-Type' => "#{content_type};charset=UTF-8",
        'Cache-Control' => 'no-store',
        'Access-Control-Allow-Origin' => '*'
      }.merge(headers), string
  end

  def accept_html
    return request.env['HTTP_ACCEPT'] && request.env['HTTP_ACCEPT'].match(/text\/html/)
  end

  def xml_response(code, string)
    halt code, {
        'Content-Type' => 'application/atom+xml;charset=UTF-8'
      }, string
  end

  def create_rpc_error(body)
    if body.class == String
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
    else
      rpc_error 400, 0, body.to_s
    end
  end

end
