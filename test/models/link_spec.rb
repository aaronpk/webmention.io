require_relative '../load'

describe Link do

  describe "author" do

    it "returns the author name if present" do
      link = Link.new
      link.author_name = "Aaron"
      link.author_url = "http://aaronparecki.com/"
      link.author_text.must_equal "Aaron"
    end

    it "returns the author url if no name is present" do
      link = Link.new
      link.author_name = nil
      link.author_url = "http://aaronparecki.com/"
      link.author_text.must_equal "http://aaronparecki.com/"
    end

  end

end
