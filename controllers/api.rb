class Controller < Sinatra::Base

  get "/api/links" do

    if params[:target].empty? and params[:access_token].empty?
      json_error 400, {
        error: "invalid_input",
        error_description: "Either an access token or a target URI is required"
      }
    end

    if params[:access_token].empty?
      target = Page.first :href => params[:target]
      if target.nil?
        json_error 404, {
          error: "not_found",
          error_description: "The specified link was not found"
        }
      end

      if !target.site.public_access
        json_error 401, {
          error: "forbidden",
          error_description: "This site does not allow public access to its pingbacks"
        }
      end

      links = target.links.all(:order => [:created_at.desc])
    else
      account = Account.first :token => params[:access_token]

      if account.nil?
        json_error 401, {
          error: "forbidden",
          error_description: "Access token was not valid"
        }
      end

      if params[:target].empty?
        links = account.sites.pages.links.all(:order => [:created_at.desc])
      else
        page = account.sites.pages.first(:href => params[:target])

        if page.nil?
          json_error 404, {
            error: "not_found",
            error_description: "There are no links for the specified page"
          }
        end

        links = page.links.all(:order => [:created_at.desc])
      end
    end

    link_array = []

    links.each do |link|
      link_array << {
        href: link.href,
        verified: link.verified == true,
        verified_date: link.updated_at
      }
    end

    json_respond 200, {
      links: link_array
    }
  end

end
