require_relative '../load'

describe Formats do

  describe "build_jf2_from_link" do

    before do
      page = Page.new :href => "http://target.example.com/"
      @link = Link.new :page => page
      @link.published = DateTime.now
      @link.published_offset = 0
      @link.created_at = DateTime.now
    end

    def to_jf2(link)
      return JSON.parse(Formats.build_jf2_from_link(@link).to_json)
    end

    it "includes syndication links if present" do
      @link.syndication = ["1","2"].to_json
      jf2 = to_jf2(@link)
      _(jf2["type"]).must_equal "entry"
      _(jf2["syndication"]).must_equal ["1","2"]
    end

    it "doesn't include syndication property if no syndication links present" do
      jf2 = to_jf2(@link)
      _(jf2["type"]).must_equal "entry"
      _(jf2["syndication"]).must_be_nil
    end

    it "includes summary if present" do
      @link.summary = "Hello World"
      jf2 = to_jf2(@link)
      _(jf2["summary"]["value"]).must_equal "Hello World"
    end

    it "includes content if present" do
      @link.content = "Hello World"
      jf2 = to_jf2(@link)
      _(jf2["content"]["value"]).must_equal "Hello World"
    end

    it "sets like-of property" do
      @link.type = "like"
      jf2 = to_jf2(@link)
      _(jf2["like-of"]).must_equal "http://target.example.com/"
      _(jf2["wm-property"]).must_equal "like-of"
    end

    it "includes timezone offset if timezone is present" do
      @link.published = DateTime.parse "2016-02-19T09:16:07"
      @link.published_offset = -28800
      jf2 = to_jf2(@link)
      _(jf2["published"]).must_equal "2016-02-19T01:16:07-08:00"
    end

    it "doesn't include timezone offset if timezone is missing" do
      @link.published = DateTime.parse "2016-02-19T01:16:07"
      @link.published_offset = nil
      jf2 = to_jf2(@link)
      _(jf2["published"]).must_equal "2016-02-19T01:16:07"
    end

  end

end
