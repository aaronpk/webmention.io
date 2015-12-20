require_relative '../load'

describe Link do

  describe "snippet" do

    it "truncates content to generate the snippet" do
      link = Link.new
      link.content = "This is a link with a lot of content, too long to be displayed normally, it should be truncated when displaying it in notificaitons."
      link.snippet.must_equal "This is a link with a lot of content, too long to be displayed normally, it shou..."
    end

  end

  describe "author_name" do

    def link_with_absolute_author_url
      link = Link.new
      link.author_name = "Aaron"
      link.author_url = "http://aaronparecki.com/"
      link
    end

    it "returns the author name if present" do
      link = link_with_absolute_author_url
      link.author_text.must_equal "Aaron"
    end

    it "returns the author url if no name is present" do
      link = link_with_absolute_author_url
      link.author_name = ""
      link.author_text.must_equal "http://aaronparecki.com/"

      link = link_with_absolute_author_url
      link.author_name = nil
      link.author_text.must_equal "http://aaronparecki.com/"
    end

    it "returns 'someone' if no author info is present" do
      link = Link.new
      link.author_text.must_equal "someone"
    end

    it "uses fallback text when no author info is present" do
      link = Link.new
      link.author_text('example').must_equal "example"
    end

  end

  describe "author_html" do

    def link_with_relative_author_url
      link = Link.new
      link.href = "http://example.com/post/100"
      link.author_name = "Example"
      link.author_url = "/about"
      link
    end

    it "returns author html with relative author url" do
      link = link_with_relative_author_url
      link.author_html.must_equal '<a href="http://example.com/about">Example</a>'
    end

    it "returns author html with relative author url and no name" do
      link = link_with_relative_author_url
      link.author_name = ""
      link.author_html.must_equal '<a href="http://example.com/about">http://example.com/about</a>'
    end

    it "returns name if no author url is present" do
      link = link_with_relative_author_url
      link.author_url = ""
      link.author_html.must_equal 'Example'
    end

    it "returns 'someone' if no author info is present" do
      link = link_with_relative_author_url
      link.author_name = ""
      link.author_url = ""
      link.author_html.must_equal 'someone'
    end

    it "returns fallback name if no author name is present" do
      link = Link.new
      link.author_name = ""
      link.author_url = ""
      link.author_html('example').must_equal 'example'
    end

    it "returns fallback url if no author url is present" do
      link = Link.new
      link.author_url = ""
      link.author_name = "Example"
      link.author_html('someone','http://brid.gy/example').must_equal '<a href="http://brid.gy/example">Example</a>'
    end

    it "returns fallback url with fallback name when no info present" do
      link = Link.new
      link.author_url = ""
      link.author_name = ""
      link.author_html('someone','http://brid.gy/example').must_equal '<a href="http://brid.gy/example">someone</a>'
    end

  end

  describe "name_truncated" do

    def link_with_long_name
      link = Link.new
      link.href = "http://example.com/post/100"
      link.name = "For some reason the name of this post is really long, far too long to be displayed normally, it should truncate it at some point"
      link.author_name = "A. Example"
      link.author_url = "/about"
      link
    end

    it "truncates the name" do
      link = link_with_long_name
      link.name_truncated.must_equal "For some reason the name of this post is really long, far too long to be display..."
    end

    it "removes whitespace from the name" do
      link = link_with_long_name
      link.name = "This name has\na linebreak"
      link.name_truncated.must_equal "This name has a linebreak"
    end

  end

  describe "published_date" do

    it "converts published date to local time" do
      link = Link.new
      link.published = DateTime.parse("2015-12-01T09:30:00+00:00")
      link.published_offset = -28800
      link.published_date.to_s.must_equal "2015-12-01T01:30:00-08:00"
    end

    it "keeps date in UTC when no offset is specified" do
      link = Link.new
      link.published = DateTime.parse("2015-12-01T09:30:00+00:00")
      link.published_date.to_s.must_equal "2015-12-01T09:30:00+00:00"
    end

  end

  describe "syndications" do

    it "returns nil for no syndications" do
      link = Link.new
      link.syndications.must_be_nil
    end

    it "returns one syndication as an array" do
      links = ["https://twitter.com/example/status/1"]
      link = Link.new
      link.syndication = links.to_json
      link.syndications.length.must_equal 1
      link.syndications.must_equal links
    end

    it "returns two syndications as an array" do
      links = ["https://twitter.com/example/status/1","https://facebook.com/1"]
      link = Link.new
      link.syndication = links.to_json
      link.syndications.length.must_equal 2
      link.syndications.must_equal links
    end

  end

  describe "emoji" do
    it "stores an emoji in the link name" do
      # Set up the required parent records
      account = Account.new
      account.save
      site = Site.new :account => account
      site.save
      page = Page.new :account => account, :site => site
      page.save

      link = Link.new :page => page, :site => site
      # Write an emoji to the name column
      link.name = '💩'
      link.save

      # Check that the database actually saved it and generated an ID
      link.id.wont_be_nil

      # Read back the record and check that the emoji was returned
      link2 = Link.get link.id
      link2.name.must_equal '💩'
    end
  end

end
