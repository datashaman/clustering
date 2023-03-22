test:
	phpunit

demo:
	php -S localhost:8080 -t demo

.PHONY: demo
