require_relative '../load'

describe WebmentionProcessor do

  describe "get_referenced_url" do

    it "returns the urls from a plain string" do
      entry = TestData.entry 'like-plain-url.html'
      url = WebmentionProcessor.new.get_referenced_url entry, 'like_ofs'
      url.must_equal ["http://target.example.org/post/100"]
    end

    it "returns the urls from a nested h-cite" do
      entry = TestData.entry 'like-h-cite.html'
      url = WebmentionProcessor.new.get_referenced_url entry, 'like_ofs'
      url.must_equal ["http://target.example.org/post/100"]
    end

  end

end
