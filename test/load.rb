require_relative '../environment'
require_relative './testdata'
require 'minitest/autorun'
require 'webmock/minitest'

# Reset the DB between test runs
DataMapper.auto_migrate!

# There appears to be no way to tell DataMapper to create the tables with utf8mb4 encoding, so alter the tables now
['accounts','links','notifications','pages','sites'].each do |table|
  DataMapper.repository.adapter.execute("ALTER TABLE #{table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
end
