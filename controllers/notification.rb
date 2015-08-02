class Controller < Sinatra::Base

  get '/notification/:token' do
    @notification = Notification.first :token => params[:token]

    @sources = @notification.links.uniq
    @targets = @notification.links.collect{|link| link.page}.uniq

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
