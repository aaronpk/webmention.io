class String
  def titleize
    split('_').map(&:capitalize).join(' ')
  end
end
