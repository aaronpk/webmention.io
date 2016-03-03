class TestData

  def self.file(file)
    IO.read File.expand_path "./test/data/#{file}"
  end

  def self.parse(file)
    Microformats2.parse IO.read File.expand_path "./test/data/#{file}"
  end

  def self.entry(file)
    parsed = self.parse file
    parsed.entry
  end

  def self.stub_requests(c)
    # Sets up stub request pattern to load files in the "data" folder.
    # Use the pattern like example.com/page/{file} to load the corresponding
    # file in test/data/page/{file}.html
    c.stub_request(:get, /^http:\/\/.+\.example\.(com|org)\/.+/).
      to_return { |request|
        host = request.uri.hostname
        path = request.uri.path.match(/\/(.+)/)[1]
        {:body => TestData.file("#{host}/#{path}.html")}
      }

    c.stub_request(:get, /^http:\/\/.+\.example\.(com|org)\/?$/).
      to_return { |request|
        host = request.uri.hostname
        {:body => TestData.file("#{host}/index.html")}
      }

    c.stub_request(:get, /^http:\/\/xray\.test\/parse?.+/).
      to_return { |request| 
        url = CGI::unescape(request.uri.query.match(/url=([^&]+)/)[1])
        path = url.gsub(/^http:\/\//, '')+".html"
        {
          :body => TestData.file("xray.test/#{path}"),
          :headers => [
            'Content-Type: application/json'
          ]
        }
      }
  end

end
