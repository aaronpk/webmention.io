require_relative '../load'

describe Link do

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

end
