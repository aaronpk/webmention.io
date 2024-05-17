class Controller < Sinatra::Base

  def get_client_id
    SiteConfig.base_url+"/"
  end

  def get_redirect_uri
    "#{SiteConfig.base_url}/auth/callback"
  end

  get '/auth/start' do
    session[:state] = SecureRandom.urlsafe_base64 16
    query_params = {
      client_id: get_client_id,
      state: session[:state],
      redirect_uri: get_redirect_uri,
      me: params[:me],
    }
    query = URI.encode_www_form query_params
    redirect "#{SiteConfig.indieauth_server}/auth?#{query}"
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

    if signed_in_uri.query != nil
      @message = "Sorry, you can't use this service if your IndieAuth URL contains a query string.<br><br>You signed in as <code>#{response.parsed_response['me']}</code><br><br>If you want to host your website at a subfolder, make sure your root domain redirects with a temporary HTTP 302 redirect."
      erb :error
    else

      user = create_user_and_log_in signed_in_uri

      # If the user has no mentions yet, redirect to the settings page
      if user.sites.pages.links(:verified => true, :deleted => false).count == 0
        redirect "/settings"
      else
        redirect "/dashboard"
      end
    end
  end
  
  def create_user_and_log_in(signed_in_uri)
    # don't include trailing slash in plain domain identities
    if signed_in_uri.path == '/'
      signed_in_uri.path = ''
    end

    domain = signed_in_uri.to_s.downcase.gsub(/^https?:\/\//, '').gsub(/\//, '_')

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

    user
  end
  
  post '/auth/fedcm-start' do
    session[:code_verifier] = SecureRandom.urlsafe_base64 30
    base64_str = Digest::SHA256.base64digest(session[:code_verifier])
    code_challenge = base64_str.tr("+/", "-_").tr("=", "")
    json_response(200, {:code_challenge => code_challenge})
  end
  
  post '/auth/fedcm-login' do
    puts request.params.inspect

    # TODO: check for Sec-Fetch-Dest header

    # Fetch the IndieAuth metadata
    response = HTTParty.get request.params['metadata_endpoint']
    config = response.parsed_response
    
    puts config.inspect
    
    metadataEndpoint = URI.parse request.params['metadata_endpoint']
    host = metadataEndpoint.host
    
    puts host
    puts "Getting token with code_verifier: #{session[:code_verifier]}"
    
    clientIDURL = URI.parse SiteConfig.base_url

    # Exchange the authorization code for profile info at the token endpoint
    response = HTTParty.post config['token_endpoint'], {
      :body => {
        :grant_type => 'authorization_code',
        :code => request.params['code'],
        :client_id => get_client_id,
        :code_verifier => session[:code_verifier],
      }
    }
    
    puts response.parsed_response.inspect
    
    if response.parsed_response && response.parsed_response['me']
      signed_in_uri = URI.parse response.parsed_response['me']
      
      # Fetch the user's profile URL and look for this FedCM configURL
      # to confirm that this FedCM server is allowed to make claims about this user
      rels = XRay.rels signed_in_uri.to_s
      if rels && rels['indieauth-metadata'] && rels['indieauth-metadata'].include?(metadataEndpoint.to_s)
        
        if signed_in_uri.query != nil
          json_response(400, {:error => 'invalid_user'})
        end

        user = create_user_and_log_in signed_in_uri

        if user.sites.pages.links(:verified => true, :deleted => false).count == 0
          path = "/settings"
        else
          path = "/dashboard"
        end
        
        json_response(200, {:redirect => path})
      else
        puts "Failed to find fedcm config URL in user website rels"
        json_response(400, {:error => 'verification_failed'})
      end
    
    else
      json_response(400, {:error => 'unknown'})
    end
    
  end

  get '/logout' do
    session[:user_id] = nil
    redirect "/#logged-out"
  end

end
