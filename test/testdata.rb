class TestData

  def self.parse(file)
    Microformats2.parse IO.read File.expand_path "./test/data/#{file}"
  end

  def self.entry(file)
    parsed = self.parse file
    parsed.entry
  end

end
