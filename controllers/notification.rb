class Controller < Sinatra::Base

  get '/notification/:token' do
    @notification = Notification.first :token => params[:token]

    @webmentions = @notification.links.sort_by{|link| link.created_at}

    if @notification.nil?
      title "Not found"
      erb :'notification/not_found'
    elsif @notification.site.public_access == false && @notification.site.account_id != session[:user_id]
      title "Forbidden"
      erb :'notification/login'
    else
      title "Notification"
      erb :'notification/view'
    end
  end

end
