class Controller < Sinatra::Base

  get '/auth/start' do
    redirect "#{SiteConfig.indieauth_server}/auth?client_id=#{SiteConfig.base_url}/&redirect_uri=#{SiteConfig.base_url}/auth/callback"
  end

  get '/auth/indieauth/callback' do
    auth = request.env["omniauth.auth"]
    puts auth.info.url.inspect

    signed_in_uri = URI.parse(auth.info.url)

    if !['','/'].include? signed_in_uri.path
      @message = "Sorry, you can't use this service if your IndieAuth URL contains a path component. Only root domains are supported.<br><br>You signed in as <code>#{auth.info.url}</code>"
      erb :error
    else
      domain = signed_in_uri.host.downcase

      user = Account.first :domain => domain

      if user.nil?
        user = Account.new
        user.username = domain
        user.domain = domain
        user.created_at = Time.now
        user.updated_at = Time.now
      end

      user.last_login = Time.now
      user.save

      session[:user_id] = user[:id]
      puts "User successfully logged in"
      redirect "/dashboard"
    end
  end

end
