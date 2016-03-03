require_relative '../load'

describe WebmentionProcessor do

  before do
    TestData.stub_requests self
    @w = WebmentionProcessor.new
    @account = Account.first_or_create :username => 'test'
    @site = Site.first_or_create :domain => "target.example.com", :account => @account
  end

  describe "creates_page_in_site" do

    it "determines the page is an entry" do
      target = "http://target.example.com/entry"
      page = @w.create_page_in_site @site, target
      page.wont_be_nil
      page.type.must_equal "entry"
      page.name.must_equal "An Entry"
      page.site.must_equal @site
      page.href.must_equal target
    end

    it "determines the page is an event" do
      target = "http://target.example.com/event"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "event"
      page.name.must_equal "An Event"
      page.site.must_equal @site
      page.href.must_equal target
    end

    it "determines the page is a photo" do
      page = @w.create_page_in_site @site, "http://target.example.com/photo"
      page.wont_be_nil
      page.type.must_equal "photo"
      page.name.must_equal "A Photo"
    end

    it "determines the page is a video" do
      target = "http://target.example.com/video"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "video"
      page.name.must_equal "A Video Post"
    end

    it "determines the page is audio" do
      target = "http://target.example.com/audio"
      page = @w.create_page_in_site @site, target
      page.type.must_equal "audio"
      page.name.must_equal "An Audio Post"
    end

  end

  describe "adds_mf2_data_to_link" do

    before do
      target = "http://target.example.com/entry"
      @page = @w.create_page_in_site @site, target
    end

    it "resolves relative url from the value in the source" do
      source = "http://source.example.org/alternate-url"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link.href.must_equal "http://source.example.org/alternate-url"
      link.url.must_equal "http://source.example.org/alternate/url"
    end

    it "uses source url when no url is in the page" do
      source = "http://source.example.org/no-explicit-url"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link.href.must_equal source
      link.url.must_be_nil # no custom URL is stored in the DB
      link.absolute_url.must_equal source # the absolute_url function returns the href value
    end

    it "gets publish date and converts to UTC" do
      source = "http://source.example.org/no-explicit-url"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link = Link.get link.id # reload from the DB because the Ruby DB wrapper keeps the .published as a datetime object
      link.published.to_s.must_equal "2015-11-07T17:00:00+00:00"
      link.published_ts.must_equal 1446915600
    end

    it "finds one syndication link" do
      source = "http://source.example.org/one-syndication"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link.syndication.must_equal "[\"https://twitter.com/example/status/1\"]"
    end

    it "finds two syndication links" do
      source = "http://source.example.org/two-syndications"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link.syndication.must_equal "[\"https://twitter.com/example/status/1\",\"https://facebook.com/1\"]"
    end

    it "sets timezone offset if published date has timezone" do
      source = "http://source.example.org/with-timezone"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link.published_offset.must_equal -28800
    end

    it "null timezone offset if published date has no timezone" do
      source = "http://source.example.org/no-timezone"
      entry = XRay.parse source, @page.href
      link = Link.new :page => @page, :href => source, :site => @site
      @w.add_mf2_data_to_link entry, link
      link.published_offset.must_be_nil
    end

  end

  describe "adds_author_to_link" do

    it "finds the author of a like" do
      target = "http://target.example.com/entry"
      page = @w.create_page_in_site @site, target

      source = "http://source.example.org/like-of"
      entry = XRay.parse source, page.href

      link = Link.new :page => page, :href => source, :site => @site

      @w.add_author_to_link entry, link

      link.author_name.must_equal "Source Author"
      link.author_photo.must_equal "http://source.example.org/photo.jpg"
      link.author_url.must_equal "http://source.example.org/"
    end

    it "finds the author of a like with no photo" do
      target = "http://target.example.com/entry"
      page = @w.create_page_in_site @site, target

      source = "http://source.example.org/like-of-no-photo"
      entry = XRay.parse source, page.href
      link = Link.new :page => page, :href => source, :site => @site

      @w.add_author_to_link entry, link

      link.author_name.must_equal "Source Author"
      link.author_photo.must_equal ""
      link.author_url.must_equal "http://source.example.org/"
    end

    it "finds the author when a URL to an h-card is given" do
      target = "http://target.example.com/entry"
      page = @w.create_page_in_site @site, target

      source = "http://source.example.org/author-is-not-an-hcard"
      entry = XRay.parse source, page.href

      link = Link.new :page => page, :href => source, :site => @site

      @w.add_author_to_link entry, link

      link.author_name.must_equal "Source Author"
      link.author_photo.must_equal ""
      link.author_url.must_equal "http://source.example.org/"
    end

    it "uses the invitee as the author for bridgy invites with no author" do
      # Bridgy doesn't know who sent the invite, so it insteads sets the invitee
      # as the author so that receiving systems display the person who was invited
      target = "http://target.example.com/"
      page = @w.create_page_in_site @site, target

      source = "http://source.example.org/bridgy-invitee-no-author"
      entry = XRay.parse source, page.href

      link = Link.new :page => page, :href => source, :site => @site

      @w.add_author_to_link entry, link

      link.author_name.must_equal ""
      link.author_photo.must_equal ""
      link.author_url.must_equal "http://target.example.com/"
    end

  end

  describe "gets_phrase_and_sets_type" do

    before do
      @link = Link.new
    end

    it "liked a post" do
      @target = "http://target.example.com/entry"
      @source = "http://source.example.org/like-of"
      @entry = XRay.parse @source, @target

      phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target

      @link.type.must_equal "like"
      phrase.must_equal "liked a post"
    end

    # TODO: fill in with Bridgy example
    # it "liked a post that linked to" do
    #   @target = ""
    #   @source = ""
    #   @entry = @w.get_entry_from_source @source
    #
    #   phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target
    #
    #   @link.type.must_equal "like"
    #   phrase.must_equal "liked a post that linked to"
    # end

    it "reshared a post" do
      @target = "http://target.example.com/entry"
      @source = "http://source.example.org/repost-of"
      @entry = XRay.parse @source, @target

      phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target

      @link.type.must_equal "repost"
      phrase.must_equal "reshared a post"
    end

    it "bookmarked a post" do
      @target = "http://target.example.com/entry"
      @source = "http://source.example.org/bookmark-of"
      @entry = XRay.parse @source, @target

      phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target

      @link.type.must_equal "bookmark"
      phrase.must_equal "bookmarked a post"
    end

    it "commented on a post" do
      @target = "http://target.example.com/entry"
      @source = "http://source.example.org/in-reply-to"
      @entry = XRay.parse @source, @target
      @link.content = @entry['content']['text']

      phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target

      @link.type.must_equal "reply"
      phrase.must_equal "commented 'Thanks for the information, it was super helpful. I am looking forward to puttin...' on a post"
    end

    it "commented on a post that linked to" do
      @target = "http://another.example.com/entry"
      @source = "http://source.example.org/in-reply-to"
      @entry = XRay.parse @source, @target
      @link.content = @entry['content']['text']

      phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target

      @link.type.must_equal "reply"
      @link.is_direct.must_equal false
      phrase.must_equal "commented 'Thanks for the information, it was super helpful. I am looking forward to puttin...' on a post that linked to"
    end

    it "generic mention" do
      @target = "http://target.example.com/entry"
      @source = "http://source.example.org/mention"
      @entry = XRay.parse @source, @target
      @link.content = @entry['content']['text']

      phrase = @w.get_phrase_and_set_type @entry, @link, @source, @target

      @link.type.must_equal "link"
      phrase.must_equal "posted 'Did you see this post over here? It was pretty great.' linking to"
    end

  end

end
