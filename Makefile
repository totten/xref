all:
	@echo "Availiable make targets:"
	@echo " test"
	@echo " package"
	@echo " clean"

test:
	## self-test:
	bin/xref-lint
	## unittests
	phpunit tests

package: test clean check_clean package.xml
	dos2unix bin/*
	unix2dos bin/xref-doc.bat bin/xref-lint.bat
	pear package
	dos2unix bin/*

package.xml:
	php dev/makePackageFile.php

clean:
	rm -rf package.xml XRef*.tgz

check_clean:
	files=`git status --porcelain`; if [ "$$files" != "" ]; then echo "extra files in dir: $$files"; exit 1; fi

