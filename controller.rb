class Controller < Sinatra::Base
  before do
    @redis = Redis.new :host => SiteConfig.redis.host, :port => SiteConfig.redis.port
  end

  def require_login
    if session[:user_id].nil?
      puts "Login required. Redirecting."
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

    title "Dashboard"
    erb :dashboard
  end

  post '/webhook/configure' do
    require_login
jj params
    site = Site.first :id => params[:site_id]
    if site
      site.callback_url = params[:callback_url]
      site.callback_secret = params[:callback_secret]
      site.archive_avatars = params[:archive_avatars] ? 1 : 0
      site.save
    end

    title "Dashboard"
    erb :dashboard
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
    if format == 'json'
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

  def json_response(code, data)
    if params[:jsonp]
      string = "#{params[:jsonp]}(#{data.to_json})"
      content_type = 'text/javascript'
    else
      string = data.to_json
      content_type = 'application/json'
    end

    halt code, {
        'Content-Type' => "#{content_type};charset=UTF-8",
        'Cache-Control' => 'no-store',
        'Access-Control-Allow-Origin' => '*'
      }, string
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
