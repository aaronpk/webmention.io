require_relative '../load'

describe WebmentionProcessor do

  before do
    # Sets up stub request pattern to load files in the "data" folder.
    # Use the pattern like example.com/page/{file} to load the corresponding
    # file in test/data/page/{file}.html
    stub_request(:get, /http:\/\/example.com\/.+/).
      to_return { |request|
        path = request.uri.path.match(/\/(.+)/)[1]
        {:body => TestData.file("example.com/#{path}.html")}
      }
  end

  describe "get_referenced_url" do

    it "returns the urls from a plain string" do
      entry = TestData.entry 'example.com/target/like-plain-url.html'
      url = WebmentionProcessor.new.get_referenced_url entry, 'like_ofs'
      url.must_equal ["http://example.com/target/like-plain-url"]
    end

    it "returns the urls from a nested h-cite" do
      entry = TestData.entry 'example.com/target/like-h-cite.html'
      url = WebmentionProcessor.new.get_referenced_url entry, 'like_ofs'
      url.must_equal ["http://example.com/target/like-h-cite"]
    end

  end

  describe "create_page_in_site" do

    before do
      @w = WebmentionProcessor.new
      @site = Site.new
    end

    it "determines the page is an entry" do
      target = "http://example.com/target/entry"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "entry"
      page.name.must_equal "An Entry"
      page.site.must_equal @site
      page.href.must_equal target
    end

    it "determines the page is an event" do
      target = "http://example.com/target/event"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "event"
      page.name.must_equal "An Event"
      page.site.must_equal @site
      page.href.must_equal target
    end

    it "determines the page is an photo" do
      target = "http://example.com/target/photo"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "photo"
      page.name.must_equal "A Photo"
    end

    it "determines the page is a video" do
      target = "http://example.com/target/video"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "video"
      page.name.must_equal "A Video Post"
    end

    it "determines the page is audio" do
      target = "http://example.com/target/audio"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "audio"
      page.name.must_equal "An Audio Post"
    end

  end

  describe "add_author_to_link" do

    it "" do

    end

  end

end
