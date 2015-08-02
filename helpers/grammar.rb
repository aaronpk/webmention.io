class Array
  # Converts the array to a comma-separated sentence where the last element is joined by the connector word. Options:
  # * <tt>:words_connector</tt> - The sign or word used to join the elements in arrays with two or more elements (default: ", ")
  # * <tt>:two_words_connector</tt> - The sign or word used to join the elements in arrays with two elements (default: " and ")
  # * <tt>:last_word_connector</tt> - The sign or word used to join the last element in arrays with three or more elements (default: ", and ")
  def join_with_and(options = {})
    default_words_connector     = ", "
    default_two_words_connector = " and "
    default_last_word_connector = ", and "

    options.assert_valid_keys(:words_connector, :two_words_connector, :last_word_connector, :locale)
    options.reverse_merge! :words_connector => default_words_connector, :two_words_connector => default_two_words_connector, :last_word_connector => default_last_word_connector

    case length
      when 0
        ""
      when 1
        self[0].to_s.dup
      when 2
        "#{self[0]}#{options[:two_words_connector]}#{self[1]}"
      else
        "#{self[0...-1].join(options[:words_connector])}#{options[:last_word_connector]}#{self[-1]}"
    end
  end
end
