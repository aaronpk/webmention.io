require_relative '../load'

describe Link do

  describe "join_with_and" do

    it "returns empty string for empty array" do
      items = []
      items.join_with_and.must_equal ""
    end

    it "returns the word when only one is given" do
      items = ["one"]
      items.join_with_and.must_equal "one"
    end

    it "returns two words joined by 'and'" do
      items = ["one","two"]
      items.join_with_and.must_equal "one and two"
    end

    it "returns uses comma and 'and' appropriately" do
      items = ["one","two","three"]
      items.join_with_and.must_equal "one, two, and three"

      items = ["one","two","three","four"]
      items.join_with_and.must_equal "one, two, three, and four"
    end

    it "allows specifying alternate word connectors" do
      items = ["one","two","three"]
      items.join_with_and(:words_connector => '! ').must_equal "one! two, and three"
      items.join_with_and(:last_word_connector => ', & ').must_equal "one, two, & three"

      items = ["one","two"]
      items.join_with_and(:two_words_connector => ' & ').must_equal "one & two"
    end

  end

end
