require_relative '../load'

describe NotificationQueue do

  before do
    TestData.stub_requests self
    @w = WebmentionProcessor.new
  end

  describe "one source linked to many targets" do

    before do
      @account = Account.create :username => 'test'
    end

    it "works with one source linking to one target" do
      @site = Site.create :domain => "example.com", :account => @account
      @page1 = Page.create :href => "http://example.com/target/entry", :site => @site, :account => @account

      source = "http://source.example.org/notification/one-to-two"
      entry = @w.get_entry_from_source source

      link1 = Link.create :page => @page1, :href => source, :site => @site
      @w.add_author_to_link entry, link1
      phrase = @w.get_phrase_and_set_type entry, link1, source, @page1

      notifications = NotificationQueue.generate_notifications @site, [link1.id], [link1.id]

      notifications.length.must_equal 1
      notifications[0].text.must_equal "Source Author wrote a post that linked to http://example.com/target/entry"
    end

    it "works with one source linking to two targets" do
      @site = Site.create :domain => "example.com", :account => @account
      @page1 = Page.create :href => "http://example.com/target/entry", :site => @site, :account => @account
      @page2 = Page.create :href => "http://example.com/target/photo", :site => @site, :account => @account

      source = "http://source.example.org/notification/one-to-two"
      entry = @w.get_entry_from_source source

      link1 = Link.create :page => @page1, :href => source, :site => @site
      link2 = Link.create :page => @page2, :href => source, :site => @site
      @w.add_author_to_link entry, link1
      @w.add_author_to_link entry, link2
      phrase = @w.get_phrase_and_set_type entry, link1, source, @page1
      phrase = @w.get_phrase_and_set_type entry, link2, source, @page2

      notifications = NotificationQueue.generate_notifications @site, [link1.id, link2.id], [link1.id, link2.id]

      notifications.length.must_equal 1
      notifications[0].text.must_equal "Source Author wrote a post that linked to http://example.com/target/entry and http://example.com/target/photo"
    end

  end

end
