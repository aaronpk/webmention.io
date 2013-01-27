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
