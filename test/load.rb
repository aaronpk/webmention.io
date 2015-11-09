require_relative '../environment'
require_relative './testdata'
require 'minitest/autorun'
require 'webmock/minitest'

# Reset the DB between test runs
DataMapper.auto_migrate!
