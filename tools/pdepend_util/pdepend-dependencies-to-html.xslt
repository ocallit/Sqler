<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

  <xsl:output method="html" indent="yes"/>

  <!-- Your main project namespace (vendor) -->
  <xsl:variable name="mainNamespace" select="'ocallit'" />
  <!-- Your specific package namespace -->
  <xsl:variable name="packageNamespace" select="'ocallit\\SqlEr'" />

  <xsl:template match="/">
    <html>
      <head>
        <title>PDepend Dependencies by Scope</title>
        <style>
          body { font-family: sans-serif; margin: 1em; }
          h2 { margin-top: 2em; }
          table { border-collapse: collapse; width: 100%; margin-bottom: 1em; }
          th, td { border: 1px solid #ccc; padding: 0.4em; text-align: left; }
          th { background: #f0f0f0; }
        </style>
      </head>
      <body>
        <h1>PDepend Dependencies by Scope</h1>

        <xsl:call-template name="render-group">
          <xsl:with-param name="title" select="'Internal (within ocallit\\SqlEr)'" />
          <xsl:with-param name="filter"
            select="//dependency[starts-with(@depender, $packageNamespace)
                              and starts-with(@dependsOn, $packageNamespace)]" />
        </xsl:call-template>

        <xsl:call-template name="render-group">
          <xsl:with-param name="title" select="'Intra-vendor (within ocallit\\, outside SqlEr)'" />
          <xsl:with-param name="filter"
            select="//dependency[starts-with(@depender, $packageNamespace)
                              and starts-with(@dependsOn, concat($mainNamespace, '\\'))
                              and not(starts-with(@dependsOn, $packageNamespace))]" />
        </xsl:call-template>

        <xsl:call-template name="render-group">
          <xsl:with-param name="title" select="'External (outside ocallit\\)'" />
          <xsl:with-param name="filter"
            select="//dependency[starts-with(@depender, $packageNamespace)
                              and not(starts-with(@dependsOn, concat($mainNamespace, '\\')))]" />
        </xsl:call-template>
      </body>
    </html>
  </xsl:template>

  <xsl:template name="render-group">
    <xsl:param name="title"/>
    <xsl:param name="filter"/>

    <h2><xsl:value-of select="$title"/></h2>
    <table>
      <tr>
        <th>From</th>
        <th>To</th>
        <th>Type</th>
      </tr>
      <xsl:for-each select="$filter">
        <tr>
          <td><xsl:value-of select="@depender"/></td>
          <td><xsl:value-of select="@dependsOn"/></td>
          <td><xsl:value-of select="@type"/></td>
        </tr>
      </xsl:for-each>
    </table>
  </xsl:template>

</xsl:stylesheet>

