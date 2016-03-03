require_relative '../load'

describe NotificationQueue do

  before do
    TestData.stub_requests self
    @w = WebmentionProcessor.new
    @account = Account.first_or_create :username => 'test'
    @site = Site.first_or_create :domain => "target.example.com", :account => @account
  end

  describe "one source linked to many targets" do

    it "works with one source linking to one target" do
      @page1 = @w.create_page_in_site @site, "http://target.example.com/entry"

      source = "http://notification.example.org/one-to-two"
      entry = XRay.parse source, @page1.href

      link1 = Link.create :page => @page1, :href => source, :site => @site
      @w.add_author_to_link entry, link1
      phrase = @w.get_phrase_and_set_type entry, link1, source, @page1

      notifications = NotificationQueue.generate_notifications @site, link1, [link1.id], [link1.id]

      notifications.length.must_equal 1
      notifications[0].text.must_equal "Source Author wrote a post that linked to an entry: \"An Entry\" http://target.example.com/entry"
    end

    it "works with one source linking to two targets" do
      @page1 = @w.create_page_in_site @site, "http://target.example.com/entry"
      @page2 = @w.create_page_in_site @site, "http://target.example.com/photo"

      source = "http://notification.example.org/one-to-two"
      entry = XRay.parse source, @page1.href

      link1 = Link.create :page => @page1, :href => source, :site => @site
      link2 = Link.create :page => @page2, :href => source, :site => @site
      @w.add_author_to_link entry, link1
      @w.add_author_to_link entry, link2
      phrase = @w.get_phrase_and_set_type entry, link1, source, @page1
      phrase = @w.get_phrase_and_set_type entry, link2, source, @page2

      notifications = NotificationQueue.generate_notifications @site, link1, [link1.id, link2.id], [link1.id, link2.id]

      notifications.length.must_equal 1
      notifications[0].text.must_equal "Source Author wrote a post that linked to an entry: \"An Entry\" http://target.example.com/entry and a photo: \"A Photo\" http://target.example.com/photo"
    end

  end

  describe "many sources linked to one target" do

    it "works with multiple likes of a post" do
      @page = @w.create_page_in_site @site, "http://target.example.com/entry"

      source1 = "http://notification.example.org/like1-of-entry"
      source2 = "http://notification.example.org/like2-of-entry"
      entry1 = XRay.parse source1, @page.href
      entry2 = XRay.parse source2, @page.href
      link1 = Link.create :page => @page, :href => source1, :site => @site
      link2 = Link.create :page => @page, :href => source2, :site => @site
      @w.add_author_to_link entry1, link1
      @w.add_author_to_link entry2, link2
      phrase = @w.get_phrase_and_set_type entry1, link1, source1, @page
      phrase = @w.get_phrase_and_set_type entry2, link2, source2, @page

      notifications = NotificationQueue.generate_notifications @site, link1, [link1.id, link2.id], [link1.id, link2.id]
      notifications.length.must_equal 1
      notifications[0].text.must_equal "Alice and Bob liked a post that linked to an entry: \"An Entry\" http://target.example.com/entry"
    end

  end

end
