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
    WebMock.disable_net_connect!(:allow => ['xray.dev','xray.p3k.io'])

    # Sets up stub request pattern to load files in the "data" folder.
    # Use the pattern like example.com/page/{file} to load the corresponding
    # file in test/data/page/{file}.html
    c.stub_request(:get, /^http:\/\/example.com\/.+/).
      to_return { |request|
        path = request.uri.path.match(/\/(.+)/)[1]
        {:body => TestData.file("example.com/#{path}.html")}
      }

    c.stub_request(:get, "http://example.com/").
      to_return { |request|
        {:body => TestData.file("example.com/index.html")}
      }

    c.stub_request(:get, /^http:\/\/source.example.org\/.+/).
      to_return { |request|
        path = request.uri.path.match(/\/(.+)/)[1]
        {:body => TestData.file("source.example.org/#{path}.html")}
      }

    c.stub_request(:get, "http://source.example.org/").
      to_return { |request|
        {:body => TestData.file("source.example.org/index.html")}
      }
  end

end
