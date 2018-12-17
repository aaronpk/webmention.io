class Controller < Sinatra::Base

  get %r{/api/count(:?\.(?<format>json))?} do
    format = params['format'] || 'json'

    if params[:target].empty?
      api_response format, 400, {
        error: "invalid_input",
        error_description: "A target URI is required"
      }
    end

    links_of_type = {}

    targets = Page.all :href => params[:target]
    if targets.count == 0
      links = 0
    else
      links = 0
      targets.each do |t|
        links += t.links.count(:verified => true, :deleted => false)
      end
      types = repository(:default).adapter.select('SELECT type, COUNT(1) AS num FROM links
        WHERE page_id IN ('+targets.map{|t| t.id}.join(',')+')
          AND deleted = 0 AND verified = 1
        GROUP BY type')
      types.each do |type|
        if type.type
          links_of_type[(type.type == "link" ? "mention" : type.type)] = type.num
        end
      end
    end

    api_response 'json', 200, {
      count: links,
      type: links_of_type
    }
  end

  get %r{/api/(links|mentions)(:?\.(?<format>json|atom|jf2|html))?} do
    format = params['format'] || 'json'

    if params[:token]
      params[:access_token] = params[:token]
    end

    if params[:perPage]
      limit = params[:perPage].to_i
    elsif params[:"per-page"]
      limit = params[:"per-page"].to_i
    else
      limit = 20
    end

    if params[:page]
      pageNum = params[:page].to_i
    else
      pageNum = 0
    end

    if params[:target].empty? and params[:access_token].empty?
      api_response format, 400, {
        error: "invalid_input",
        error_description: "Either a token or a target URL is required"
      }
    end

    opts = {
      :verified => true,
      :deleted => false,
      :order => [],
      :offset => (pageNum * limit),
      :limit => limit
    }

    if params[:"sort-dir"]
      orderDir = (params[:"sort-dir"] == "down" ? :desc : :asc)
    else
      orderDir = :desc
    end

    if params[:"sort-by"]
      if params[:"sort-by"] == "rsvp"
        # can't find a way to provide this raw SQL to datamapper, ugh
        # opts[:order] << 'FIELD(type, "rsvp-no","rsvp-interested","rsvp-maybe","rsvp-yes") DESC'
        opts[:order] << :created_at.send(orderDir)
      elsif params[:"sort-by"] == "published"
        opts[:order] << :published.send(orderDir)
        opts[:order] << :created_at.send(orderDir)
      elsif params[:"sort-by"] == "updated"
        opts[:order] << :updated_at.send(orderDir)
      else
        opts[:order] << :created_at.send(orderDir)
      end
    else
      opts[:order] << :created_at.send(orderDir)
    end

    if params[:"wm-property"]
      prop = params[:"wm-property"]

      if prop.class == String
        prop = [prop]
      end

      wm_type = []

      prop.each do |pp|
        if pp == "rsvp"
          wm_type += ["rsvp-yes","rsvp-no","rsvp-maybe","rsvp-interested"]
        elsif pp == "mention-of"
          wm_type = "link"
        elsif
          wm_type << pp.gsub(/^in-/,'').gsub(/-(to|of)$/,'')
        end
      end

      opts[:type] = wm_type
    end

    if params[:since]
      opts[:created_at.gt] = params[:since]
    end
    if params[:since_id]
      opts[:id.gt] = params[:since_id].to_i
    end


    if params[:target].empty?
      # access token required for everything except target requests

      account = Account.first :token => params[:access_token]

      if account.nil?
        return api_response format, 401, {
          error: "forbidden",
          error_description: "Access token was not valid"
        }
      end

      @account = account

      if params[:domain]
        site = Site.first :domain => params[:domain]
        if site
          opts[:site] = site
          links = account.links.all(opts)
        else
          links = []
        end
      else
        links = account.links.all(opts)
      end

    else

      targets = Page.all :href => params[:target]

      if targets.nil?
        return api_response format, 200, {
          links: []
        }
      end

      links = targets.links.all(opts)

      # sort by RSVP since i can't get it to sort using SQL
      # this is a horrible hack
      if params[:"sort-by"] == "rsvp"
        links = links.to_a
        if params[:"sort-dir"] == "up"
          rsvp_value_order = {"rsvp-no" => 0, "rsvp-interested" => 1, "rsvp-maybe" => 2, "rsvp-yes" => 3}
        else
          rsvp_value_order = {"rsvp-no" => 3, "rsvp-interested" => 2, "rsvp-maybe" => 1, "rsvp-yes" => 0}
        end
        links.sort! {|a, b|
          if rsvp_value_order[a[:type]] and rsvp_value_order[b[:type]]
            rsvp_value_order[a[:type]] <=> rsvp_value_order[b[:type]]
          else
            a[:created_at] <=> b[:created_at]
          end
        }
      end
    end

    if format == 'json'
      api_response format, 200, Formats.links_to_json(links)
    elsif format =='jf2'
      api_response format, 200, Formats.links_to_jf2(links)
    elsif format == 'html'
      @links = links
      erb :mentions
    else
      api_response format, 200, Formats.links_to_atom(links)
    end
  end

end
