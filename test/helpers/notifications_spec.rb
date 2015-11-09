require_relative '../load'

describe NotificationQueue do

  before do
    TestData.stub_requests self
    @w = WebmentionProcessor.new
    # Reset the DB between tests
    DataMapper.auto_migrate!
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

  end

end
