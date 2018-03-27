<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
 
<xsl:template match="@*|node()">
  <xsl:copy>
    <xsl:apply-templates select="@*|node()"/>
  </xsl:copy>
</xsl:template>
<xsl:template match='database'>
  <xsl:copy>
    <xsl:attribute name='noXsd'>true</xsl:attribute>
    <xsl:apply-templates select="@*|node()"/>
  </xsl:copy>
</xsl:template>
<xsl:template match="table">
<xsl:variable name="tableName" select='@name'/>
  <xsl:copy>
    <xsl:for-each select="//table[(contains(@name,'_i18n') or contains(@name,'_I18N')) and (column/@name='culture' or column/@name='CULTURE') and foreign-key/@foreignTable=$tableName]">
        <xsl:attribute name="isI18N">true</xsl:attribute>
        <xsl:attribute name="i18nTable"><xsl:value-of select="@name"/></xsl:attribute>
    </xsl:for-each>
    <xsl:apply-templates select="@*|node()"/>
  </xsl:copy>
</xsl:template>
<xsl:template match="//table[(contains(@name,'_i18n') or contains(@name,'_I18N')) and foreign-key/@foreignTable]/column[translate(@name,'culture','CULTURE')='CULTURE']">
 <xsl:copy>
  <xsl:attribute name="isCulture">true</xsl:attribute>
  <xsl:apply-templates select="@*|node()"/>
 </xsl:copy>
</xsl:template>
</xsl:stylesheet>