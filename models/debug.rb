class Debug
  include DataMapper::Resource

  property :id, Serial

  property :page_url, String, :length => 256, :index => true
  property :enabled, Boolean, :default => 0
end
