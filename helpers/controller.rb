class Controller < Sinatra::Base
  helpers do 

    def title(value=nil)
      return @_title if value.nil?
      @_title = value
    end

    def viewport
      '<meta name="viewport" content="width=device-width,initial-scale=1">' if @_mobile
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
  
  end
end