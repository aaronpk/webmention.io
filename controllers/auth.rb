class Controller < Sinatra::Base

  def get_client_id
    SiteConfig.base_url+"/"
  end

  def get_redirect_uri
    "#{SiteConfig.base_url}/auth/callback"
  end

  get '/auth/start' do
    session[:state] = SecureRandom.urlsafe_base64 16
    redirect "#{SiteConfig.indieauth_server}/auth?client_id=#{URI.encode_www_form_component(get_client_id)}&state=#{session[:state]}&redirect_uri=#{URI.encode_www_form_component(get_redirect_uri)}"
  end

  get '/auth/callback' do
    puts request.params.inspect

    response = HTTParty.post SiteConfig.indieauth_server+"/auth", {
      :body => {
        :code => request.params['code'],
        :client_id => get_client_id,
        :redirect_uri => get_redirect_uri,
      }
    }

    puts response.parsed_response

    if !response.parsed_response['me']
      session[:state] = nil
      redirect "/auth/start"
    end

    signed_in_uri = URI.parse(response.parsed_response['me'])

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

      # If the user has no mentions yet, redirect to the settings page
      if user.sites.pages.links(:verified => true, :deleted => false).count == 0
        redirect "/settings"
      else
        redirect "/dashboard"
      end
    end
  end

  get '/logout' do
    session[:user_id] = nil
    redirect "/"
  end

end
