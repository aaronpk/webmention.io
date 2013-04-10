class Controller < Sinatra::Base

  # Forward pingbacks to webmentions
  post '/webmention' do

    # Check for a valid "forward" parameter
    if !params[:forward]
      rpc_error 404, 0, "Not Found"
    end

    # Validate the url checking for http or https protocol
    uri = URI.parse params[:forward]

    if !['http','https'].include? uri.scheme
      rpc_error 400, 0, "Invalid 'forward' parameter"
    end

    xml = request.body.read.force_encoding "UTF-8"
    if !xml.valid_encoding?
      rpc_error 400, 0, "Invalid string encoding"
    end
    method, arguments = XMLRPC::Marshal.load_call(xml)

    if method != "pingback.ping"
      rpc_error 404, 0, "Method not found"
    end

    source, target = arguments

    begin
      response = RestClient.post params[:forward], {
        source: source,
        target: target
      }, {
        'Accept' => 'application/json'
      }
    rescue => e
      create_rpc_error(e.respond_to?('response') ? e.response : e)
    end

    # Find out if the request succeeded or failed

    if response.code == 202
      begin
        # Attempt to parse the JSON body
        json = JSON.parse response.body
        if json and json.class == Hash and json['result']
          rpc_respond 200, json['result']
        end
      rescue
        # fall through
      end
      # If the body was not JSON, or did not contain a message, return a generic message
      rpc_respond 200, "Pingback from #{source} to #{target} was successful!"
    else
      create_rpc_error response.body
    end
  end

end