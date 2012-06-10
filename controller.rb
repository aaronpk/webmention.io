class Controller < Sinatra::Base
  before do 
    # puts "================="
    # puts "Path: #{request.path}"
    # puts "IP: #{request.ip}"
    # puts 

    # Require login on everything except home page
    if request.path.match /[a-zA-Z0-9_\.]\/xmlrpc/
      # No login required for /xmlrpc routes
    else
      if !["/", "/auth/github", "/auth/github/callback"].include? request.path
        puts request.body.read
        require_login
      end
    end
  end

  def require_login
    if session[:user_id].nil?
      puts "Login required. Redirecting."
      redirect "/"
    end

    @user = User.get session[:user_id]
    if @user.nil?
      puts "No user found. Redirecting."
      redirect "/"
    end
  end

  get '/?' do
    erb :index
  end

  get '/dashboard/?' do
    title "Dashboard"
    erb :dashboard
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

    if valid
      link.verified = true
      link.save
      rpc_respond 200, "Pingback from #{source} to #{target} was successful! Keep the web talking!"
    else
      rpc_error 200, 0x0011, "There appears to be no link to us!"
    end

    # See http://www.hixie.ch/specs/pingback/pingback for a list of error codes to return
  end

  # Authentication

  get '/auth/github/callback' do
    auth = request.env["omniauth.auth"]
    user = User.first :username => auth["info"]["nickname"]
    if user.nil?
      puts "Unauthorized github login"
      title "Unauthorized"
      @message = "Sorry, you are not authorized to log in"
      erb :error
    else
      user.last_login_date = Time.now
      if user.email == '' && auth["info"]["email"]
        user.email = auth["info"]["email"]
      end
      user.save
      session[:user_id] = user[:id]
      puts "User successfully logged in"
      redirect "/dashboard/"
    end
  end

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

  def json_error(code, data)
    return [code, {
        'Content-Type' => 'application/json;charset=UTF-8',
        'Cache-Control' => 'no-store'
      }, 
      data.to_json]
  end

  def json_respond(code, data)
    return [code, {
        'Content-Type' => 'application/json;charset=UTF-8',
        'Cache-Control' => 'no-store'
      }, 
      data.to_json]
  end

end
