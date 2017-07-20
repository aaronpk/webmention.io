class Controller < Sinatra::Base
  helpers do 

    def title(value=nil)
      return @_title if value.nil?
      @_title = value
    end

    def partial(page, options={})
      erb page, options.merge!(:layout => false)
    end

    def path_class
      classes = request.path.split('/')
      classes.push('home') if request.path == '/'

      #if logged_in?
      #  classes.push('logged-in')
      #else
      #  classes.push('logged-out')
      #end

      classes.join(" ")
    end

    def request_headers
      env.inject({}){|acc, (k,v)| acc[$1.downcase] = v if k =~ /^http_(.*)/i; acc}
    end  
  
    def csrf_token(path=nil)
      JWT.encode({user_id: session[:user_id], path: path, exp: Time.now.to_i + 3600}, SiteConfig.session_secret, 'HS256')
    end

    def verify_csrf(path=nil)
      if params[:csrf].empty?
        redirect "/"
      end

      begin
        jwt = JWT.decode params[:csrf], SiteConfig.session_secret, true, { :algorithm => 'HS256' }
        puts jwt[0].inspect
        if path != jwt[0]['path'] || jwt[0]['user_id'] != session[:user_id]
          redirect "/"
        else
          return true
        end
      rescue JWT::ExpiredSignature
        redirect "/"
      end

      redirect "/"
    end

  end
end