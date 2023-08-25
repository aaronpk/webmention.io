require_relative '../load'

describe Link do

  describe "published_date" do

    it "converts published date to local time" do
      link = Link.new
      link.published = DateTime.parse("2015-12-01T09:30:00+00:00")
      link.published_offset = -28800
      _(link.published_date.to_s).must_equal "2015-12-01T01:30:00-08:00"
    end

    it "keeps date in UTC when no offset is specified" do
      link = Link.new
      link.published = DateTime.parse("2015-12-01T09:30:00+00:00")
      _(link.published_date.to_s).must_equal "2015-12-01T09:30:00+00:00"
    end

  end

  describe "absolute_url" do
    
    it "returns the absolute url" do
      link = Link.new
      link.href = "https://example.com/foo/"
      link.url  = "/bar"
      _(link.absolute_url).must_equal "https://example.com/bar"
    end
    
  end

  describe "syndications" do

    it "returns nil for no syndications" do
      link = Link.new
      _(link.syndications).must_be_nil
    end

    it "returns one syndication as an array" do
      links = ["https://twitter.com/example/status/1"]
      link = Link.new
      link.syndication = links.to_json
      _(link.syndications.length).must_equal 1
      _(link.syndications).must_equal links
    end

    it "returns two syndications as an array" do
      links = ["https://twitter.com/example/status/1","https://facebook.com/1"]
      link = Link.new
      link.syndication = links.to_json
      _(link.syndications.length).must_equal 2
      _(link.syndications).must_equal links
    end

  end

  describe "emoji" do
    it "stores an emoji in the link name" do
      # Set up the required parent records
      # account = Account.new
      # account.save
      # site = Site.new :account => account
      # site.save
      # page = Page.new :account => account, :site => site
      # page.save

      account = Account.get 4
      site = account.sites.first
      page = site.pages.first

      link = Link.new :page => page, :site => site, :account => account
      # Write an emoji to the name column
      link.name = '💩'
      link.save

      # Check that the database actually saved it and generated an ID
      _(link.id).wont_be_nil

      # Read back the record and check that the emoji was returned
      link2 = Link.get link.id
      _(link2.name).must_equal '💩'
    end
  end

end
