class Controller < Sinatra::Base

  def stats_keys
    ['success','dns_error','connect_error','timeout','ssl_error','ssl_cert_error',
      'ssl_unsupported_cipher','too_many_redirects','no_content','invalid_content',
      'no_link_found','unknown_error']
  end

  get '/stats/:type/data' do
    now = Time.now.to_i
    past = now - 300

    # First remove old data
    stats_keys.each do |key|
      @redis.zremrangebyscore "webmention.io:stats:#{params[:type]}:#{key}", '-inf', (past - 86400)
    end

    # Count the data for each key
    response = ""

    stats_keys.each do |key|
      count = @redis.zcount "webmention.io:stats:#{params[:type]}:#{key}", past, now
      response += "#{key}.value #{count}\n"
    end

    halt 200, {
      'Content-type' => 'text/plain'
    }, response
  end

  get '/stats/:type/config' do
    response = "graph_title Webmention.io - #{params[:type]}\n"
    response += "graph_info Counts the number of success and failures of incoming #{params[:type]} requests\n"
    response += "graph_vlabel requests per 5 minutes\n"
    response += "graph_category webmention\n"
    response += "graph_args --lower-limit 0\n"
    response += "graph_scale yes\n"

    stats_keys.each do |key|
      #response += "#{key}.type "
      response += "#{key}.label #{key}\n"
    end

    halt 200, {
      'Content-type' => 'text/plain'
    }, response
  end

end
