# Wikipedia-Good-Articles-Bot
@wikigoodarticle - Robot posting on Twitter random good articles from Wikipedia.

Good article criteria: https://en.wikipedia.org/wiki/Wikipedia:Good_article_criteria

The program is using the php library twitteroauth 

High level, the program is using wikipedia API to get 'Good Articles', then checks which articles were already posted by @wikigoodarticle to avoid duplicates. 

To avoid Wikipedia stereotypes, Google Knowledge Graph API is also used to avoid tweeting categories already tweeted.

There is a logic ensure male/female equality and avoid recurring articles about chemical elements.

If some images exist and have correct size and format, the program tweets images along with the article title, short description (from wikidata), URL.
